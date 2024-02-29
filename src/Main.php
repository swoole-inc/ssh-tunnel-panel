<?php

namespace App;

use PyCore;
use PyDict;
use PyList;
use Swoole\Process;

/**
 * @property $text_name
 * @property $radio_type_ssh
 * @property $text_host
 * @property $text_port
 * @property $text_remote_port
 * @property $text_local_port
 * @property $text_password
 * @property $input_auth_type
 * @property $text_user
 * @property $label_error
 * @property $label_password
 * @property $tree
 * @property $header_bar
 * @property $window
 * @property $model
 * @property $image_computer
 * @property $file_idrsa
 * @property $text_remarks
 * @property $checkbox_show_password
 */
class Main
{
    public $builder;
    public $Gtk;
    public $Gio;
    public $detail;
    public $icon_empty;
    public $GdkPixbuf;
    private array $form_empty = [
        "name" => "",
        "type" => "ssh",
        "host" => "",
        "port" => "22",
        "remote_port" => "",
        "local_port" => "",
        "user" => "root",
        "password" => "",
        "auth_type" => "密码",
        "remarks" => "",
    ];
    private mixed $builtins;
    private Ssh $serviceSsh;
    private $GLib;
    protected $icon_yes;
    protected $icon_no;
    private $itemMap = [];

    const COL_ICON = 7;
    public Process $childProcess;
    private $IOChannel;

    function __construct()
    {
        $operator = PyCore::import("operator");
        $this->builtins = PyCore::import("builtins");
        $types = PyCore::import('types');
        $gi = PyCore::import('gi');
        $gi->require_version("Gtk", "3.0");

        $this->Gtk = PyCore::import('gi.repository.Gtk');
        $this->GLib = PyCore::import('gi.repository.GLib');
        $this->Gio = PyCore::import('gi.repository.Gio');
        $this->GdkPixbuf = PyCore::import('gi.repository.GdkPixbuf');

        Config::load();

        $this->builder = $this->Gtk->Builder();
        $this->builder->add_from_file(ROOT_PATH . '/ui/main.ui');
        $this->builder->add_from_file(ROOT_PATH . '/ui/detail.ui');

        $this->window->set_title("SSH Tunnel Manager");
        $this->window->set_icon_from_file(ROOT_PATH . '/icons/scalable/app.svg');
        $this->window->show_all();

        $detail = $this->builder->get_object('dialog');
        $detail->set_transient_for($this->window);
        $this->detail = $detail;

        $this->icon_empty = $this->GdkPixbuf->Pixbuf->new($this->GdkPixbuf->Colorspace->RGB, true, 8, 24, 24);
        $this->icon_empty->fill(0);
        $this->icon_yes = $this->GdkPixbuf->Pixbuf->new_from_file_at_scale(ROOT_PATH . '/icons/scalable/status-online.svg', 16, 16, true);
        $this->icon_no = $this->GdkPixbuf->Pixbuf->new_from_file_at_scale(ROOT_PATH . '/icons/scalable/status-offline.svg', 16, 16, true);
        $this->serviceSsh = new Ssh();
    }

    function setButtons($btns)
    {
        foreach ($btns as $btn) {
            $btn_object = $this->builder->get_object($btn);
            $action = $btn_object->get_related_action();
            $btn_object->set_image(
                $this->Gtk->Image->new_from_icon_name(
                    icon_name: $action->get_icon_name(),
                    size: $this->Gtk->IconSize->BUTTON)
            );
            $btn_object->set_always_show_image(True);
            if (!$action->get_is_important()) {
                $btn_object->props->label = null;
            }
        }
    }

    public function setHandlers($handlers)
    {
        $this->builder->connect_signals(new PyDict($handlers));
    }

    public function saveWindowPosition()
    {
        $pos = $this->window->get_position();
        $size = $this->window->get_size();

        Config::$config['window']['width'] = $size[0];
        Config::$config['window']['height'] = $size[1];
        Config::$config['window']['left'] = $pos[0];
        Config::$config['window']['top'] = $pos[1];
        Config::save();
    }

    public function restoreWindowPosition()
    {
        $this->window->resize(
            Config::$config['window']['width'],
            Config::$config['window']['height']
        );
        $this->window->move(
            Config::$config['window']['left'],
            Config::$config['window']['top']
        );
    }

    private function validPort($port)
    {
        return is_numeric($port) and intval($port) < 65536 and intval($port) > 0;
    }

    private function getFormValue(): bool|array
    {
        $form = [];
        $form['name'] = $this->text_name->get_text();
        $form['host'] = $this->text_host->get_text();
        $form['port'] = $this->text_port->get_text();
        $form['remote_port'] = $this->text_remote_port->get_text();
        $form['local_port'] = $this->text_local_port->get_text();
        $form['user'] = $this->text_user->get_text();
        $form['auth_type'] = $this->input_auth_type->get_active_text();
        foreach ($form as $k => &$value) {
            if (PyCore::len($value) == 0) {
                $this->showFormError("字段 $k 输入的值不能为空");
                return false;
            }
            $value = strval($value);
        }

        $form['remarks'] = strval($this->text_remarks->get_text());
        $form['password'] = strval($this->text_password->get_text());

        $file = $this->file_idrsa->get_file();
        if ($file) {
            $form['idrsa'] = $this->file_idrsa->get_filename()->__toString();
        } else {
            $form['idrsa'] = '';
        }

        if ($form['auth_type'] == '密码') {
            if (PyCore::len($form['password']) == 0) {
                $this->showFormError("密碼不能为空");
                return false;
            }
        }

        $form['type'] = 'ssh';

        if (!filter_var($form['host'], FILTER_VALIDATE_IP)) {
            $this->showFormError('错误的服务器地址，请填写正确的 IP 地址格式');
            return false;
        }
        if (!$this->validPort($form['port'])) {
            $this->showFormError('错误的服务器端口，必须为1~65535之间的数字');
            $this->text_port->grab_focus();
            return false;
        }
        if (!$this->validPort($form['remote_port'])) {
            $this->showFormError('错误的远程端口，必须为1~65535之间的数字');
            $this->text_remote_port->grab_focus();
            return false;
        }
        if (!$this->validPort($form['local_port'])) {
            $this->showFormError('错误的本地端口，必须为1~65535之间的数字');
            $this->text_local_port->grab_focus();
            return false;
        }
        return $form;
    }

    private function showFormError(string $msg)
    {
        $this->label_error->set_property('visible', true);
        $this->label_error->set_label('<b><span foreground="red">' . $msg . '</span></b>');
    }

    public function delete($selected)
    {
        $name = $this->model[$selected][0];
        unset(Config::$config['list'][strval($name)]);
        Config::save();
        $this->model->remove($selected);
    }

    private function setFormValue($form)
    {
        $this->text_name->set_text($form['name']);
        $this->text_host->set_text($form['host']);
        $this->text_port->set_text($form['port']);
        $this->text_remote_port->set_text($form['remote_port']);
        $this->text_local_port->set_text($form['local_port']);
        $this->text_user->set_text($form['user']);
        $this->text_password->set_text($form['password']);
        $this->input_auth_type->set_active_id($form['auth_type']);
        if (!empty($form['remarks'])) {
            $this->text_remarks->set_text($form['remarks']);
        } else {
            $this->text_remarks->set_text('');
        }
        if (!empty($form['idrsa'])) {
            $this->file_idrsa->set_file($this->Gio->File->new_for_path($form['idrsa']));
        } else {
            $this->file_idrsa->unselect_all();
        }
    }

    public function showEditDialog($add)
    {
        $this->label_error->set_property('visible', False);
        $this->detail->set_title('添加机器');
        $this->checkbox_show_password->set_active(false);

        if ($add) {
            $this->setFormValue($this->form_empty);
        } else {
            $selected = $this->getSelectedItem();
            if (!$selected) {
                echo "未选中，无法打开编辑窗口\n";
                return;
            }
            $name = $this->model[$selected][0];
            $this->setFormValue(Config::$config['list'][strval($name)]);
        }

        while (true) {
            $response = $this->detail->run();
            if ($response == $this->builtins->int($this->Gtk->ResponseType->OK)) {
                $form = $this->getFormValue();
                if (!$form) {
                    continue;
                }
                if ($add and array_key_exists($form['name'], Config::$config['list'] ?? [])) {
                    $this->showFormError('已存在此名称，请重新输入');
                    $this->text_name->grab_focus();
                    continue;
                }
                Config::$config['list'][$form['name']] = $form;
                Config::save();
                if ($add) {
                    $this->add($form);
                } else {
                    $this->set($form);
                }
            }
            break;
        }
        $this->detail->hide();
    }

    public function trigger($type, $data = null)
    {
        $this->childProcess->write(serialize(['type' => $type, 'data' => $data]));
    }

    public function add($form)
    {
        $item = $this->parseFormData($form);
        $this->trigger('add', $form);
        $this->itemMap[$form['name']] = $this->model->append(new PyList($item));
    }

    public function set($form)
    {
        $item = $this->parseFormData($form);
        $selected = $this->getSelectedItem();
        $this->model[$selected] = new PyList($item);
        $this->trigger('set', $form);
    }

    public function quit()
    {
        $this->saveWindowPosition();
        $this->Gtk->main_quit();
    }

    public function getObject(string $string)
    {
        return $this->builder->get_object($string);
    }

    public function getSelectedItem()
    {
        return $this->tree->get_selection()->get_selected()->__getitem__(1);
    }

    public function showConfirmMessageBox($message, $title, $default_response = null)
    {
        $dialog = $this->Gtk->MessageDialog(
            parent: $this->window,
            flags: $this->Gtk->DialogFlags->MODAL,
            type: $this->Gtk->MessageType->QUESTION,
            buttons: $this->Gtk->ButtonsType->YES_NO,
            message_format: $message,
        );

        $dialog->set_title($title);
        if ($default_response) {
            $dialog->set_default_response($default_response);
        }
        $response = $dialog->run();
        $dialog->destroy();
        return $response;
    }

    private function parseFormData($form)
    {
        return [
            $form['name'],
            $form['type'],
            false,
            $form['host'],
            intval($form['port']),
            intval($form['remote_port']),
            intval($form['local_port']),
            $this->icon_empty,
            $form['remarks'] ?? ''
        ];
    }

    protected function eventHandler()
    {
        $data = $this->childProcess->read();
        if (!$data) {
            return;
        }
        $event = unserialize($data);
        switch ($event['type']) {
            case 'checkLocalPort':
                $name = $event['data']['name'];
                $local_port = $event['data']['local_port'];
                $index = $this->itemMap[$name];
                // 检查端口是否可用
                $connection = @fsockopen('localhost', $local_port);
                if (!$connection) {
                    $this->model[$index][self::COL_ICON] = $this->icon_no;
                } else {
                    $this->model[$index][self::COL_ICON] = $this->icon_yes;
                }
                break;
            default:
                var_dump($event);
                break;
        }
        $this->GLib->io_add_watch($this->IOChannel, $this->GLib->PRIORITY_DEFAULT_IDLE, $this->GLib->IO_IN, PyCore::fn([$this, 'eventHandler']));
    }

    protected function runServices()
    {
        $this->childProcess = new Process(function () {
            $this->serviceSsh->main($this);
        });
        register_shutdown_function(function () {
            $this->trigger('shutdown');
        });
        $this->childProcess->start();

        $sock = $this->childProcess->exportSocket()->fd;
        $this->IOChannel = $this->GLib->IOChannel->unix_new($sock);
        $this->GLib->io_add_watch($this->IOChannel, $this->GLib->PRIORITY_DEFAULT_IDLE, $this->GLib->IO_IN, PyCore::fn([$this, 'eventHandler']));
    }

    public function run()
    {
        $this->runServices();

        $this->header_bar->props->title = $this->window->get_title();
        $this->window->set_titlebar($this->header_bar);

        $list = Config::$config['list'] ?? [];
        foreach ($list as $form) {
            $this->add($form);
        }

        $handlers = [
            "on_action_quit_activate" => function () {
                echo "on_action_quit_activate\n";
                $this->quit();
            },
            "on_window_delete_event" => function () {
                echo "on_window_delete_event\n";
                $action_quit = $this->getObject('action_quit');
                $action_quit->activate();
            },
            "on_cell_selected_toggled" => function ($event, $index) {
                echo "on_cell_selected_toggled\n";
                $model = $this->tree->get_model();
                $row = $model[$index];
                $row[2] = !$row[2];
            },
            "on_tree_button_release_event" => function () {
                echo __LINE__ . ": on_tree_button_release_event\n";
            },
            "on_action_deselect_all_activate" => function () {
                echo __LINE__ . ": on_action_deselect_all_activate\n";
            },
            "on_action_options_menu_activate" => function () {
                echo __LINE__ . ": on_action_options_menu_activate\n";
            },
            'on_checkbox_show_password_toggled' => function ($ev) {
                echo __LINE__ . ": on_checkbox_show_password_toggled\n";
                $this->text_password->set_visibility($ev->get_active());
            },
            "on_action_edit_activate" => function () {
                echo __LINE__ . ": on_action_edit_activate\n";
                $this->showEditDialog(add: false);
            },
            "on_action_add_activate" => function () {
                echo __LINE__ . ": on_action_add_activate\n";
                $this->showEditDialog(add: true);
            },
            "on_action_import_ethers_activate" => function () {
                echo __LINE__ . ": on_action_import_ethers_activate\n";
            },
            "on_action_select_all_activate" => function () {
                echo __LINE__ . ": on_action_select_all_activate\n";
            },
            "on_action_open_terminal" => function () {
                echo __LINE__ . ": on_action_open_terminal\n";
                $this->openTerminal();
            },
            "on_action_shortcuts_activate" => function () {
                echo __LINE__ . ": on_action_shortcuts_activate\n";
            },
            "on_action_turnon_activate" => function () {
                echo __LINE__ . ": on_action_turnon_activate\n";
            },
            "on_action_about_activate" => function () {
                echo __LINE__ . ": on_action_about_activate\n";
            },
            "on_action_delete_activate" => function () {
                echo __LINE__ . ": on_action_delete_activate\n";
                $selected = $this->getSelectedItem();
                if ($selected) {
                    if ($this->showConfirmMessageBox(
                            '确定要删除此节点?',
                            '删除',
                            $this->Gtk->ResponseType->NO
                        ) == $this->builtins->int($this->Gtk->ResponseType->YES)) {
                        $this->delete($selected);
                    }
                }
            },
            "on_tree_row_activated" => function () {
                echo __LINE__ . ": on_tree_row_activated\n";
            },
            "on_radio_request_type_toggled" => function () {
                echo __LINE__ . ": on_tree_row_activated\n";
            },
            "on_input_auth_type_changed" => function ($event) {
                echo __LINE__ . ": on_input_auth_type_changed\n";
                $pwd_visible = strval($event->get_active_text()) == '密码';
                $this->text_password->set_property('visible', $pwd_visible);
                $this->label_password->set_property('visible', $pwd_visible);
            },
        ];

        $btns = [
            'button_add',
            'button_edit',
            'button_delete',
            'button_about',
            'button_options',
            'button_turnon',
        ];

        $this->setButtons($btns);
        $this->setHandlers($handlers);

        $this->image_computer->set_from_file(ROOT_PATH . '/icons/computer.png');

        $this->restoreWindowPosition();

        putenv('phpy_display_exception=on');
        $this->Gtk->main();
    }

    public function __get(string $key)
    {
        return $this->getObject($key);
    }

    function getIcon($icon_name, $size)
    {
        $theme = $this->Gtk->IconTheme->get_default();
        return $theme->load_icon(
            icon_name: $icon_name,
            size: $size,
            flags: $this->Gtk->IconLookupFlags->USE_BUILTIN
        );
    }

    private function openTerminal()
    {
        $selected = $this->getSelectedItem();
        $name = $this->model[$selected][0];
        $form = Config::$config['list'][strval($name)];
        $this->serviceSsh->openTerminal($form);
    }
}
