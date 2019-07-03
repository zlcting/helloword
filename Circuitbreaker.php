<?php
namespace Middleware;

// 时间窗口10个桶
define("BUCKET_NUM", 10);
// 成功率大于该值为健康
define("HEALTHY_RATE", 0.8);

// 熔断后停止所有流量5秒
define("BREAK_PERIOD", 5);
// 完全恢复需要再花费3秒
define("RECOVER_PERIOD", 3);

class Circuitbreaker {
    private static $instance = null;

    private $service = '';
    private $buckets = [];//包装变量

    private $curTime = 0; //更新时间
    
    public $redis = null;
    public $redis_key = 'house_circuitbreaker';
    public $hash;
    public $api_url;
    public $status;
    public $breakTime;

    public function __construct($service)
    {
       $this->service = $service;

       $this->redis = \Cache\Redis::getInstance();
        
    }
    /*单例*/
    public static function getInstance($service)
    {
        self::$instance = new self($service);
        
        return self::$instance;
    }


    //数据读取
    private function shiftBuckets()
    {
        $this->service = parse_url($this->service);
        $this->hash = md5($this->service['host'].$this->service['path']);
        $this->api_url = $this->service['host'].$this->service['path'];

        $obj_redis_data = $this->redis->hGet($this->redis_key, $this->hash);
        $obj_redis_data = json_decode($obj_redis_data,true);

        $now = time();  
        $obj_redis_data['curTime'] = isset($obj_redis_data['curTime'])?$obj_redis_data['curTime']:0;
        $timeDiff = $now - $obj_redis_data['curTime'];

        if (!$timeDiff) {
            return;
        }

        if ($timeDiff >= BUCKET_NUM) {
            $this->buckets = array_fill(0, BUCKET_NUM, ['success' => 0, 'fail' => 0]); 
        } else {
            $this->buckets = array_merge(
                array_slice($obj_redis_data['buckets'], $timeDiff, BUCKET_NUM - $timeDiff),
                array_fill(0, $timeDiff, ['success' => 0, 'fail' => 0])
            );
        }
        $this->curTime = $now;
        $this->breakTime = isset($obj_redis_data['breakTime'])?$obj_redis_data['breakTime']:0;
        $this->status = isset($obj_redis_data['status'])?$obj_redis_data['status']:1;
    }

    //成功
    public function success()
    {
        $this->shiftBuckets();

        $this->buckets[count($this->buckets) - 1]['success']++;
        $this->setBuckets();
    }

    //失败
    public function fail()
    {
        $this->shiftBuckets();

        $this->buckets[count($this->buckets) - 1]['fail']++;
        $this->setBuckets();

    }

    //更新令牌桶存redis
    public function setBuckets($status = 1){
        $data['buckets'] = $this->buckets;
        $data['curTime'] = $this->curTime;
        $data['api_url'] = $this->api_url;
        $data['breakTime'] = $this->breakTime;
        $data['status'] = $this->status;

        $a = $this->redis->hSet($this->redis_key, $this->hash, $data,'json');
    }

    //判断当前接口健康状态
    public function isHealthy()
    {
        $this->shiftBuckets();

        $success = 0;
        $fail = 0;
        foreach ($this->buckets as $bucket) {
            $success += $bucket['success'];
            $fail += $bucket['fail'];
        }

        $total = $success + $fail;
        if ($total < 10) { // 少于10个请求的样本太少，不计算成功率
            return true;
        }

        return ($success * 1.0 / $total) >= HEALTHY_RATE;
    }




    public function isBreak()
    {
        $now = time();
        $isHealthy = $this->isHealthy();
        $breakLastTime = $now - $this->breakTime;

        $isBreak = false;

        switch ($this->status) {
            case 1:
                if (!$isHealthy) {
                    $this->status = 2;
                    $this->breakTime = time();
                    $isBreak = true;
                    echo '触发熔断' . PHP_EOL ;
                }
                break;
            case 2:
                if ($breakLastTime < BREAK_PERIOD || !$isHealthy) {
                    $isBreak = true;
                } else {
                    $this->status = 3;
                    echo '进入恢复' . PHP_EOL;
                }
                break;
            case 3:
                if (!$isHealthy) {
                    $this->status = 2;
                    $this->breakTime = time();
                    $isBreak = true;
                    echo '恢复期间再次熔断' . PHP_EOL;
                } else {
                    if ($breakLastTime >= BREAK_PERIOD + RECOVER_PERIOD) {
                        $this->status = 1;
                        echo '恢复正常' . PHP_EOL;
                    } else {
                        $passRate = $breakLastTime * 1.0 / (BREAK_PERIOD + RECOVER_PERIOD);
                        if (mt_rand() / mt_getrandmax() > $passRate) {
                            $isBreak = true;
                        }
                    }
                }
                break;
        }
        return $isBreak;
    }

}

?>
