<?php

namespace App;

use Swoole\Process;

class Ssh
{
    private $pids = [];

    function service($serverName = '127.0.0.1', $serverPort = 9999)
    {

    }

    function main(Main $app)
    {
        $proc = $app->childProcess;
        $proc->setBlocking(true);
        while (true) {
            $data = $proc->read();
            if ($data === '') {
                break;
            }
            $event = unserialize($data);
            switch ($event['type']) {
                case 'shutdown';
                    exit(0);
                case 'add':
                    $this->add($event['data']);
                    // 等待1秒钟，等待监听，避免端口检查失败
                    sleep(1);
                    $app->trigger('checkLocalPort', $event['data']);
                    break;
                case 'set':
                    $this->set($event['data']);
                    // 等待1秒钟，等待监听，避免端口检查失败
                    sleep(1);
                    $app->trigger('checkLocalPort', $event['data']);
                    break;
                default:
                    var_dump('error', $event);
                    break;
            }
        }
    }

    private function getPid($cmd)
    {
        $cmd_check = 'ps aux|grep "' . $cmd . '" | grep -v grep | awk \'{print $2}\'';
        $pid = shell_exec($cmd_check);
        if (!$pid) {
            return false;
        } else {
            return trim($pid);
        }
    }

    private function set($form)
    {
        $name = $form['name'];
        if (isset($this->pids[$name])) {
            $pid = $this->pids[$name];
            Process::kill($pid, SIGTERM);
        }
        $n = 2;
        while ($n--) {
            $tunnel = "ssh -f -L {$form['local_port']}:127.0.0.1:{$form['remote_port']}";
            $pid = $this->getPid($tunnel);
            if ($pid === false) {
                $this->add($form);
                break;
            } else {
                sleep(1);
                Process::kill($pid, SIGKILL);
            }
        }
    }

    private function add($form)
    {
        $name = $form['name'];
        $args = $this->parseArgs($form);
        $tunnel = "ssh -f -L {$form['local_port']}:127.0.0.1:{$form['remote_port']}";
        $cmd = "$tunnel $args {$form['host']} -N";
        $pid = $this->getPid($tunnel);
        if ($pid) {
            $this->pids[$name] = $pid;
        } else {
            $pexpect = \PyCore::import('pexpect');
            try {
                $proc = $pexpect->spawn($cmd . ' -o ExitOnForwardFailure=yes',
                    ignore_sighup: true,
                    timeout: 20,
                );
            } catch (\PyError $e) {
                echo "创建进程失败: " . $e->getMessage();
            }
            while (true) {
                $list = [
                    '(yes/no)',
                    'password:',
                    'Could not request local forwarding.',
                    'Permission denied, please try again.',
                    $pexpect->EOF,
                ];
                try {
                    $index = $proc->expect($list);
                    if ($index == 0) {
                        $proc->send("yes\r");
                    } elseif ($index == 1) {
                        $proc->send($form['password'] . "\r");
                    } elseif ($index == 2) {
                        echo "监听端口失败";
                        return false;
                    } elseif ($index == 3) {
                        echo "SSH 密码错误，访问被拒绝";
                        return false;
                    } else {
                        break;
                    }
                } catch (\PyError $e) {
                    echo "超时: " . $e->getMessage();
                }
            }
            $pid = $this->getPid($tunnel);
            if ($pid) {
                $this->pids[$name] = $pid;
            }
        }
        return true;
    }

    public function parseArgs($form)
    {
        $ssh = '';
        $args['p'] = $form['port'];
        $args['l'] = $form['user'];
        if (!empty($form['idrsa'])) {
            $args['i'] = $form['idrsa'];
        }
        foreach ($args as $k => $v) {
            $ssh .= '-' . $k . ' ' . $v . ' ';
        }
        return $ssh;
    }

    public function openTerminal($form)
    {
        $args = $this->parseArgs($form);
        $ssh = "ssh $args {$form['host']}";
        shell_exec('gnome-terminal -- bash -c "' . $ssh . '"');
    }
}
