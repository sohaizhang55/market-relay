<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use app\lib\TelegramService;
use app\business\service\AdminNoticeService;
use app\lib\MarketWorkers;

/**
 * 常驻指令心跳健康检查
 *
 * 扫描 CommandHeartBeatService 上报的心跳 Key，发现「指令进程 PID 仍存活但心跳中断」
 * （即事件循环已卡死）时通过 Telegram 通知；心跳恢复后发送恢复通知。
 * 通过 xadmin:task 每 15 秒调度一次，对各常驻指令零侵入。
 *
 * Redis 双 Key 设计（与 MarketPriceCheckExpire 一致）：
 *   - REDIS_KEY_ALERTED  TTL=REPUSH_TTL：已发送告警的指令集合，集合不变时不刷新 TTL，到期重推
 *   - REDIS_KEY_STALE    无 TTL ：当前已知中断的指令集合，用于检测恢复（不能随告警 key 一起过期）
 */
class CommandHeartbeatCheckExpire extends Command
{
    use \app\lib\AlertFanoutTrait;

    // key 的定义在写者那一侧（CommandHeartBeatService），这里只引用，不再各写一遍
    private const REGISTRY_KEY      = \app\lib\CommandHeartBeatService::REGISTRY_KEY;
    private const HEARTBEAT_PREFIX  = \app\lib\CommandHeartBeatService::KEY_PREFIX;
    private const STOPPING_PREFIX   = \app\lib\CommandHeartBeatService::KEY_STOPPING_PREFIX;
    private const REDIS_KEY_ALERTED = 'heartbeat_health:alerted_cmds'; // TTL=60s
    private const REDIS_KEY_STALE   = 'heartbeat_health:stale_cmds';   // 无过期
    /**
     * 重复实例告警的去重 key。与中断告警分开：重复会一直存在到有人去处理，
     * 不去重就是每 15 秒一条，而被刷屏的告警等于没有告警。
     */
    private const REDIS_KEY_DUP_ALERTED = 'heartbeat_health:dup_cmds';  // TTL=60s
    /** 「任务已停」的去重 key：存当前问题集合的签名，理由同上 */
    private const REDIS_KEY_TASK_ALERTED = 'heartbeat_health:task_dead';  // TTL=REPUSH_TTL
    private const ALERT_TTL = 60;

    /**
     * 持续未处理的问题隔多久重推一次。按检查周期（15s）或 ALERT_TTL（60s）重推是刷屏，
     * 只推一次又会漏看 —— 取中间值，且只在问题集合变化时才重置计时。
     */
    private const REPUSH_TTL = 1800;

    /** 判「一直失败」前至少要跑够几轮，避免把启动期的首轮失败当成故障 */
    private const FAIL_MIN_RUNS = 3;

    protected function configure()
    {
        // ⚠️ 必须与 config/console.php 里的**注册键**一致。
        // 键式注册（'名字' => Class::class）会覆盖 setName，于是这里写
        // 'heartbeat:check_expire' 时，那个名字**根本调不起来**
        // （php think heartbeat:check_expire -> There are no commands defined in
        // the "heartbeat" namespace），而类里却写着它 —— 谁照着类去调都会失败。
        $this->setName('command_heartbeat:check_expire')
            ->addOption('force', null, Option::VALUE_NONE, 'Delete Redis state keys before running to force re-notify all stale commands')
            ->addOption('verify', null, Option::VALUE_NONE, '只体检并按结果返回退出码，不发 Telegram、不写差量状态（给 deploy 脚本用）')
            ->setDescription('Scan all registered long-running commands for interrupted heartbeats and notify admins via Telegram');
    }

    protected function execute(Input $input, Output $output)
    {
        $siteName = self::siteName();
        $redis = redis_client();
        if (!$redis) {
            $output->writeln('[ERROR] Redis unavailable, skip check.');
            return;
        }

        // --force：清除差量状态，强制重推
        if ($input->getOption('force')) {
            $redis->del(self::REDIS_KEY_ALERTED);
            $redis->del(self::REDIS_KEY_STALE);
            $output->writeln('[FORCE] Redis state cleared, will re-notify all stale commands.');
        }

        // 1. 逐个检查已注册指令的心跳 Key 是否仍存在
        $registered = $redis->sMembers(self::REGISTRY_KEY) ?: [];

        // ⚠️ 判据是两个 key：只看 cmd key 分不出计划内停机与真故障，见 KEY_STOPPING_PREFIX
        $staleCmds = [];
        foreach ($registered as $cmdName) {
            if ($redis->exists(self::HEARTBEAT_PREFIX . $cmdName)) continue;
            if ($redis->exists(self::STOPPING_PREFIX . $cmdName)) continue;
            $staleCmds[] = $cmdName;
        }

        // 1b. 「本该常驻、但心跳根本不在」的进程。
        //
        // ⚠️ 上面那一段永远发现不了它们：注册表是**进程自己启动时写进去的**
        // (CommandHeartBeatService::start -> sAdd)，一个从来没启动过的进程压根不在里面，
        // 于是「忘了启动」这件事没有任何告警。market:price_fallback、market:kline_backfill 与 market:funding 正是这种 ——
        // 忘启动的后果是 K 线缺口没人补、
        // 资金费率彻底断供（market:funding 是唯一供数通道），要等下游结算时才暴露。
        //
        // 与「心跳中断」分开列：两者的处置方向完全不同 ——
        // 中断是「进程在但事件循环卡了」，缺失是「根本没人把它拉起来」。
        // ⚠️ 判据是**清单**，不是注册表里有没有它。
        // heartbeat:registry 只 sAdd、全仓没有一处 sRem —— 一个指令只要在这台机器上跑过一次，
        // 名字就永远留在里面。于是"忘了启动"在跑过的机器上会被上面那段误报成
        // 「PID 可能仍存活，但事件循环已卡死」，在全新机器上则彻底看不见。两种都不对。
        // 对清单里的进程，"没心跳"就是"没在跑"，至于是没启动、已退出还是卡死，
        // 处置的第一步都是同一个：去看它为什么不在。
        $expected = MarketWorkers::expected();
        $missing  = [];
        foreach ($expected as $worker => $purpose) {
            if ($redis->exists(self::HEARTBEAT_PREFIX . $worker)) continue;
            if ($redis->exists(self::STOPPING_PREFIX . $worker)) continue;  // 正在停，同上
            $missing[$worker] = $purpose;
        }
        // 没有心跳是**正确**状态的进程（已下线的、没启用的选源、不再有源的采集器），不是故障。
        // 注册表从不 sRem，它们的名字会永远挂在"中断"里恒定误报。
        $noHeartbeatOk = MarketWorkers::noHeartbeatOk();

        // 1d. 「同一个指令有多套实例在跑」。
        //
        // ⚠️ 上面三段全都发现不了它：heartbeat:cmd:{name} 是 setex **覆盖写**的，
        // 4 个实例和 1 个实例写出来的 key 一模一样，于是判活侧看到的永远是「齐全」。
        // 现网因此叠出过 4 个合约撮合引擎同时撮同一个订单簿、4 个结算引擎同时结同一批仓位，
        // 而所有检查都说正常 —— 又一次「判定健康的依据，和真正在工作的东西不是同一件事」。
        // 判据换成 CommandHeartBeatService 的实例注册表（每实例一个 field），见那里的注释。
        //
        // 与「中断」「缺失」分开列：那两种是「没在跑」，这一种是「跑太多」，处置方向相反。
        $dupCmds = [];
        foreach (array_unique(array_merge($registered, array_keys($expected))) as $cmdName) {
            $inst = \app\lib\CommandHeartBeatService::liveInstances($cmdName);
            // 只按**本机**实例判重：跨机同名是正常部署形态（同一份代码装在多台机器上），
            // 而 posix_kill 也验不了别的机器上的 pid
            $mine = array_values(array_filter($inst, static fn($i) => $i['host'] === \app\lib\CommandHeartBeatService::hostName()));
            if (count($mine) > 1) {
                $dupCmds[$cmdName] = array_map(static fn($i) => (int)$i['pid'], $mine);
            }
        }

        // 清单内的从"中断"里摘出去，避免同一个进程被两种口径各报一次
        $staleCmds = array_values(array_diff($staleCmds, array_keys($missing), $noHeartbeatOk));
        $staleCmds = array_merge($staleCmds, array_keys($missing));

        // --verify：只体检，不发通知、不动差量状态。供 deploy/restart_market.sh 在下发完
        // 之后核对「该起的是不是真起来了」，与运行期告警共用同一份 MarketWorkers::expected() 清单，
        // 不在 bash 里另抄一份（抄两份必然漂移）。
        if ($input->getOption('verify')) {
            $output->writeln('[VERIFY] 应常驻 ' . count($expected) . ' 个进程');
            // ⚠️ 自己数一遍，不复用 $missing：它跳过"正在停"的进程，而那正是本命令最该报的
            $verifyMissing = [];
            foreach ($expected as $worker => $purpose) {
                $ok  = $redis->exists(self::HEARTBEAT_PREFIX . $worker);
                if (!$ok) $verifyMissing[$worker] = $purpose;
                $dup = $dupCmds[$worker] ?? [];
                // 重复优先于 OK 显示：一个「有心跳」但跑了 4 份的进程，报 [OK] 是误导
                $mark = $dup ? '[DUP] ' : ($ok ? '[OK]  ' : '[MISS]');
                $note = $dup
                    ? sprintf('本机有 %d 个实例在跑（pid=%s）', count($dup), implode(',', $dup))
                    : ($ok ? '' : $purpose);
                $output->writeln(sprintf('  %s %-32s %s', $mark, $worker, $note));
            }
            if ($dupCmds) {
                $output->writeln('[FAIL] 上述 [DUP] 的指令在本机有多套实例并行。'
                    . "\n       处置：ps -eo pid,lstart,args | grep 'WorkerMan: master process'"
                    . '，对多余的 master 发 kill -SIGINT，确认 ps 清空再重启。');
                return 1;
            }
            // ⚠️ 只看 $verifyMissing，不能带上 $staleCmds。
            // 后者是「注册表里有名字但心跳停了」的原始集合，在这里还没经过 $noHeartbeatOk 过滤，
            // 里面装着本机**本来就该停**的进程（已下线的、没启用的选源进程），
            // 它们不在跑是正确状态。带上它就是恒定失败 ——
            // 第一次上生产就报了「4 个全 OK 但 [FAIL]」—— 一个恒定误报的告警等于没有告警。
            if ($verifyMissing) {
                $output->writeln('[FAIL] 上述 [MISS] 的进程没有心跳。'
                    . 'CommandHeartBeatService::start() 会立刻写第一次心跳，所以只要进程 fork 完就该有 ——'
                    . '刚 restart 完等几秒重跑仍然缺失，就是真没起来：查 runtime/log/<worker名>.log');
                return 1;
            }
            $output->writeln('[OK] 应常驻的进程心跳齐全');
            return 0;
        }

        // 1c. 「心跳正常，但它该做的事早就不做了」。
        //
        // ⚠️ 这是上面两段都发现不了的第三种失效，也是最难察觉的一种：
        // 进程 PID 在、事件循环在转、心跳每 5 秒照写 —— 而业务那个 Timer 已经不跑了。
        // Workerman **吞掉定时器回调里的异常并继续调度**（Timer::tick 与
        // Events\Select::safeCall 都是 catch 之后 echo），所以这不是理论上的可能，
        // 是随便一次 Redis 抖动或一个没接住的异常就会到达的状态。
        // 补数、选源都挂在这种 Timer 上，停了没有任何人会知道。
        //
        // 判据：任务自报的 last_run 超过「该任务平均周期 × 3」且至少 60 秒。
        // 阈值自校准（用 uptime/runs 估平均间隔）而不是写死每个任务的周期 ——
        // 写死就得跟着代码同步，而迟早不同步，那时它会变成恒定误报。
        //
        // ⚠️ 去重按任务名集合、不按正文：正文里的"停了 123s"每轮都在变，拿它当签名等于没去重
        $deadTasks = $this->findDeadTasks($redis, $registered);
        $deadSig   = $this->signature($deadTasks);
        $deadNew   = $deadTasks && $deadSig !== (string)$redis->get(self::REDIS_KEY_TASK_ALERTED);
        if ($deadNew) {
            $lines = [];
            foreach ($deadTasks as $cmd => $tasks) $lines[] = "{$cmd}：" . implode('、', array_values($tasks));
            $content = "进程在但定时任务停了：\n" . implode("\n", $lines);
            $this->fanout('常驻任务已停', $content, 'error', "[{$siteName}] ⚠️ *常驻任务已停*\n{$content}");
            business_log('heartbeat_health', "[TASK_DEAD] {$content}", 'error');
            $output->writeln("[ALERT] {$content}");
        }
        // 集合没变就不动这个 key，让 TTL 自然走完 —— 到点 get 落空，下一轮重推
        if ($deadNew) {
            $redis->setex(self::REDIS_KEY_TASK_ALERTED, self::REPUSH_TTL, $deadSig);
        } elseif (!$deadTasks) {
            $redis->del(self::REDIS_KEY_TASK_ALERTED);   // 全恢复了，下次再坏立刻报
        }

        // 1e. 重复实例告警。带 TTL 去重，理由见 REDIS_KEY_DUP_ALERTED
        if ($dupCmds && !$redis->exists(self::REDIS_KEY_DUP_ALERTED)) {
            $lines = [];
            foreach ($dupCmds as $cmd => $pids) {
                $lines[] = sprintf('%s：%d 个实例（pid=%s）', $cmd, count($pids), implode(',', $pids));
            }
            $content = "进程重复启动：\n" . implode("\n", $lines)
                . "\n处置：对多余的 master 发 kill -SIGINT 后重启";
            $this->fanout('常驻指令重复启动', $content, 'error', "[{$siteName}] ⚠️ *常驻指令重复启动*\n{$content}");
            business_log('heartbeat_health', "[DUPLICATE] {$content}", 'error');
            $output->writeln("[ALERT] {$content}");
            $redis->setex(self::REDIS_KEY_DUP_ALERTED, self::ALERT_TTL, (string)count($dupCmds));
        }

        // 2. 差量状态
        $prevAlerted = $this->redisGetList($redis, self::REDIS_KEY_ALERTED);
        $prevStale   = $this->redisGetList($redis, self::REDIS_KEY_STALE);

        $newStale  = array_values(array_diff($staleCmds, $prevAlerted));
        $recovered = array_values(array_diff($prevStale, $staleCmds));

        // 3. 发送中断告警
        if (!empty($newStale)) {
            // 分开描述：缺失是"没人拉起来"，中断是"进程在但事件循环卡了"，处置方向相反
            $newMissing = array_values(array_intersect($newStale, array_keys($missing)));
            $newHung    = array_values(array_diff($newStale, $newMissing));
            $parts = [];
            if ($newHung) {
                $parts[] = "心跳中断（进程可能卡死）：\n" . implode("\n", $newHung);
            }
            if ($newMissing) {
                $lines = array_map(fn($w) => "{$w}（{$missing[$w]}）", $newMissing);
                $parts[] = "进程没在跑：\n" . implode("\n", $lines);
            }
            $content = implode("\n\n", $parts);
            // 两条通道都发。
            //
            // ⚠️ 原来只有 Telegram 这一条，而它的返回值被丢弃 —— Telegram 挂掉（token 失效、
            // 出网被墙、正文里下划线个数为奇数被拒）时，「进程没起来」这类最要紧的告警
            // **一个人都收不到，而且没有任何迹象**。价格告警那一侧一直是两条通道
            // （notifyAllAdmins + Telegram），这里口径统一过来。
            $this->fanout('指令心跳中断', $content, 'error', "[{$siteName}] ⚠️ *指令心跳中断*\n{$content}");
            business_log('heartbeat_health', "[STALE] {$content}", 'warning');
            $output->writeln("[ALERT] {$content}");
        }

        // 4. 发送恢复通知
        if (!empty($recovered)) {
            $content = '心跳已恢复：' . implode('、', $recovered);
            $this->fanout('指令心跳已恢复', $content, 'info', "[{$siteName}] ✅ *指令心跳已恢复*\n{$content}");
            business_log('heartbeat_health', "[RECOVERED] {$content}", 'info');
            $output->writeln("[RECOVER] {$content}");
        }

        // 5. 更新状态
        //
        // ⚠️ 只在集合变化时才刷新 alerted。每轮无条件 setex 的话它永远等不到过期，
        // 「到期重推」就成了一句空话 —— 一条告警只推一次，漏看再也不会被提醒。
        if (json_encode($staleCmds) !== json_encode($prevAlerted)) {
            $redis->setex(self::REDIS_KEY_ALERTED, self::REPUSH_TTL, json_encode($staleCmds));
        }
        $redis->set(self::REDIS_KEY_STALE, json_encode($staleCmds));

        if (empty($newStale) && empty($recovered)) {
            $staleCount = count($staleCmds);
            $suffix = $staleCount > 0 ? "（{$staleCount} 个持续中断，60s 后将重推告警）" : '';
            $output->writeln("[OK] 全部指令心跳正常{$suffix}");
        }
    }

    /**
     * 找出「心跳正常但任务已停」的指令。
     *
     * @param array<int,string> $registered
     * @return array<string,array<string,string>> 指令名 => [任务名 => 描述]。
     *         按任务名建键，signature() 才分得出换了一个任务在停
     */
    private function findDeadTasks($redis, array $registered): array
    {
        $out   = [];
        $nowMs = (int)(microtime(true) * 1000);

        foreach ($registered as $cmd) {
            $raw = $redis->get(self::HEARTBEAT_PREFIX . $cmd);
            if (!$raw) continue;                      // 没心跳的那一类由上面两段负责，不重复报
            $hb = json_decode((string)$raw, true);
            if (!is_array($hb) || empty($hb['tasks']) || !is_array($hb['tasks'])) continue;

            // uptime 用心跳自己的 tick 推算：tick × interval。
            // 不读 MarketMetrics 的 uptime_sec —— 那是另一条上报链路，
            // 它坏掉时这里会跟着瞎，而两条判据不该共用一个可能坏的输入
            $uptime = max(1, (int)($hb['tick'] ?? 1) * \app\lib\CommandHeartBeatService::DEFAULT_INTERVAL);

            $dead = [];
            foreach ($hb['tasks'] as $bucket => $t) {
                $last = (int)($t['last'] ?? 0);
                if ($last <= 0) continue;             // 从没跑过：可能是条件注册的任务，不判
                $runs   = max(1, (int)($t['runs'] ?? 1));
                $avgSec = $uptime / $runs;
                $limit  = (int)(max(60, $avgSec * 3) * 1000);
                $idle   = $nowMs - $last;

                if ($idle > $limit) {
                    $dead[$bucket] = sprintf('%s(停了%ds，平均%.1fs一轮)', $bucket, (int)($idle / 1000), $avgSec);
                    continue;
                }

                // 「在跑但一直失败」是另一种故障，处置方向与「停了」相反：
                // 停了要去查为什么没被调度，一直失败要去看它抛的是什么。
                // 两者在 last_run 上长得一模一样（都在被调度），只有 fails 分得开。
                // 阈值取"平均每轮都失败一次"的量级：偶发一两次不报，持续失败才报。
                //
                // ⚠️ 先要够样本量。guard() 在 finally 里记账，抛异常那次也算一轮，
                // 于是首轮就失败会得到 runs=1/fails=1，比例判据必然成立 ——
                // 而 Redis 没连上、WS 没握手这类首轮失败在启动期很常见，那不是故障。
                $fails = (int)($t['fails'] ?? 0);
                if ($runs >= self::FAIL_MIN_RUNS && $fails >= $runs * 0.5) {
                    $dead[$bucket] = sprintf('%s(在跑但累计失败%d次/共%d轮，查 runtime/log 的 task_error)',
                        $bucket, $fails, $runs);
                }
            }
            if ($dead) $out[$cmd] = $dead;
        }
        return $out;
    }

    /** 问题集合的签名：只取指令名与任务名，不含随每轮变化的秒数与次数 */
    private function signature(array $deadTasks): string
    {
        $keys = [];
        foreach ($deadTasks as $cmd => $tasks) {
            foreach (array_keys($tasks) as $bucket) $keys[] = "{$cmd}:{$bucket}";
        }
        sort($keys);
        return md5(implode('|', $keys));
    }

    private function redisGetList($redis, string $key): array
    {
        $json    = $redis->get($key);
        $decoded = $json ? json_decode($json, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

}
