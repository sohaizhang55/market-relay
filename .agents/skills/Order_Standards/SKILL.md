# [PROTOCOL] 订单安全与 Service 统一通讯协议 (v1.0)
# 维护者: market-relay Agent

本协议定义了项目中订单处理的安全性底线以及 Service 层与 Controller 层之间的通讯标准。

---

## 1. ORDER_PRIVACY_PROTOCOL (订单隐私协议)

### 核心禁令：
- **严禁暴露 ID**：前端 API 接口（Request & Response）严禁传递或返回数据库自增 `id` 字段。
- **强制使用 OrderNo**：所有订单的查询、撤单、结算及详情获取，必须统一使用 **`order_no`** (订单号) 作为唯一业务键。
- **查询过滤规范**：在 Controller 层接收过滤参数时，禁止使用 `id` 或 `order_id` 作为 Key，必须使用 `order_no`。

### 代码示例 (Controller):
```php
// [WRONG] 错误做法
$order = Db::name('orders')->where('id', $id)->find();

// [RIGHT] 正确做法
$order = Db::name('orders')->where('order_no', $orderNo)->find();
```

---

## 2. SERVICE_COMM_PROTOCOL (Service 通讯标准)

### 核心要求：
- **强制继承**：所有 Service 类必须继承 `think\admin\Service` 并引入 `app\service\trait\ServiceResult` Trait。
- **统一返回格式**：所有 Service 的业务方法必须返回 `array` 格式，且结构严格固定为：
  - `[bool $status, string $msg, mixed $data]`
- **错误处理**：严禁在 Service 内部随意抛出 `Exception` 给 Controller。应使用 `$this->toError($langKey)` 返回错误，由 Controller 决定如何呈现。

### 成功返回规范：
- 如果业务逻辑成功，必须使用 `$this->toSuccess($data, $msg)`。
- 对于订单创建方法，`$data` 部分应仅包含 `order_no` 字符串。

### 示例代码 (Service):
```php
public function createOrder(...): array 
{
    // ... 逻辑处理
    if (!$success) return $this->toError('insufficient_balance');
    return $this->toSuccess($orderNo, 'order_created');
}
```

---

## 3. API_RET_PROTOCOL (API 响应规范)

- **订单成功响应**：下单成功后，返回给前端的 JSON 结构中，订单标识符必须命名为 `order_no`。
```json
{
  "code": 1,
  "msg": "order_success",
  "data": { "order_no": "S2026..." }
}
```

---

## 4. 执行约束 (Enforcement)

1. 在修改任何 `app/service/` 或 `app/api/controller/` 下的代码时，Agent **必须**执行此协议。
2. 历史代码重构时，应优先将 `order_id` 传参方式重构为 `order_no` 方式。
