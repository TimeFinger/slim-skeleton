<?php
namespace Application\Cores;

use Interop\Container\ContainerInterface;
use Medoo\Medoo;

use Application\Cores\Commons\Singleton\SingletonTrait;
use Application\Cores\Commons\Singleton\SingletonRegister;

/**
 * 模型基类
 */
class Model
{
    use SingletonTrait;

    protected $database;
    protected static $table = '';

    public $pk = 'id';

    private $setting = [];

    protected static $connections = [];

    private static $container = null;

    private $read = true;

    private function __construct()
    {
        if (!(self::$container instanceof ContainerInterface)) {
            throw new \Exception('Container in Model is not init.');
        }
        $settings = self::$container->get('settings')['db-cluster'];
        $this->setting = [
            'master'    =>  $settings['master'][array_rand($settings['master'])],
            'slave'     =>  $settings['slave'][array_rand($settings['slave'])],
        ];
    }

    /**
     * 设置container的值(控制器基类调用)
     */
    public static function setContainer(ContainerInterface $container)
    {
        self::$container = $container;
    }

    /**
     * 重写SingletonTrait中的方法，调用getInstance方法前执行
     */
    protected static function beforeGetInstance($table = '')
    {
        if ($table) {
            static::$table = $table;
        }
        if (!static::$table) {
            throw new \Exception("Table can't be empty.");
        }
    }

    /**
     * 获取Medoo对象
     */
    public function getConnection()
    {
        $type = $this->read ? 'slave' : 'master';
        $setting = $this->setting[$type];
        $key = md5(json_encode($setting));
        return SingletonRegister::getInstance()->get($key, function () use ($setting) {
            return new Medoo($setting);
        });
    }

    /**
     * 增删改查操作
     * e.g.
     * $model->select/get/has...($where, $columns, $join);
     * $model->insert($data);
     * $model->update($where, $data);
     * $model->delete($where);
     */
    public function __call($method, $params)
    {
        $result = null;

        $this->read = in_array($method, ['select', 'get', 'has', 'count', 'sum', 'max', 'min', 'avg']);

        if ($this->read) {
            $params[1] = isset($params[1]) ? $params[1] : '*';
            if (isset($params[2]) && !empty($params[2])) {
                $result = $this->getConnection()->$method(static::$table, $params[2], $params[1], $params[0]);
            } else {
                $result = $this->getConnection()->$method(static::$table, $params[1], $params[0]);
            }
        }
        
        $this->write = in_array($method, ['insert', 'update', 'delete']);
        if ($this->write) {
            switch ($method) {
                case 'insert':
                    $this->getConnection()->$method(static::$table, $params[0]);
                    $result = $this->getConnection()->id();
                    break;
                case 'update':
                case 'delete':
                    if (!$params[0]) {    // 更新/删除条件不为为空，防止更新/删除全部数据
                        throw new \Exception(ucfirst($method) . ' condition cann\'t be empty.');
                    }
                    $cond = $method == 'update' ? $params[1] : $params[0];
                    $pdostate = $this->getConnection()->$method(static::$table, $cond, $params[0]);
                    $result = $pdostate->rowCount();
                    break;
            }
        }

        return $result;
    }

    /**
     * 开启事务
     */
    public function beginTransaction()
    {
        $this->getConnection()->pdo->beginTransaction();
    }

    /**
     * 回滚事务
     */
    public function rollBack()
    {
        $this->getConnection()->pdo->rollBack();
    }

    /**
     * 提交事务
     */
    public function commit()
    {
        $this->getConnection()->pdo->commit();
    }

    /**
     * 是否在事务中
     */
    public function inTransaction()
    {
        $this->getConnection()->pdo->inTransaction();
    }

    /**
     * 事务封装
     */
    public function action($callback)
    {
        $this->getConnection()->action();
    }

    /**
     * 最后一条执行的sql
     */
    public function lastSql()
    {
        return $this->getConnection()->last();
    }
}
