<?php
require_once __DIR__ . '/cli_only.php';
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';
$datatextbotget = select("textbot", "*",null ,null ,"fetchAll");
$datatxtbot = array();
foreach ($datatextbotget as $row) {
    $datatxtbot[] = array(
        'id_text' => $row['id_text'],
        'text' => $row['text']
    );
}
$datatextbot = array(
    'text_usertest' => '',
    'text_support' => '',
    'text_help' => '',
    'text_sell' => '',
    'text_affiliates' => '',
    'text_Add_Balance' => ''
);
foreach ($datatxtbot as $item) {
    if (isset($datatextbot[$item['id_text']])) {
        $datatextbot[$item['id_text']] = $item['text'];
    }
}
if(!is_file('info'))return;
if(!is_file('users.json'))return;


$userid = json_decode(file_get_contents('users.json'));
if(is_file('info')){
$info = json_decode(file_get_contents('info'),true);
}
$count = 0;
if(count($userid) == 0){
    if(isset($info['id_admin'])){
    deletemessage($info['id_admin'], $info['id_message']);
    sendmessage($info['id_admin'], "📌 عملیات برای تمامی کاربران درخواستی انجام شد.", null, 'HTML');
    if (!empty($info['broadcast_id'])) {
        update("broadcast_log", "status", "completed", "id", intval($info['broadcast_id']));
        refresh_broadcast_report_message(intval($info['broadcast_id']));
    }
    unlink('info');
    unlink('users.json');
    }
    return;
    
}
$count_remein = count($userid);
$textprocces = "✏️ عملیات ارسال پیام درحال انجام می باشد...

تعداد نفرات باقی مانده :  $count_remein";
$cancelmessage = json_encode([
        'inline_keyboard' => [
            [
                ['text' => "لغو عملیات", 'callback_data' => 'cancel_sendmessage'],
            ],
        ]
    ]);
if (!empty($info['id_admin']) && !empty($info['id_message'])) {
    Editmessagetext($info['id_admin'], $info['id_message'],$textprocces, $cancelmessage);
}

$broadcast_id = intval($info['broadcast_id'] ?? 0);
$btnmessage = $info['btnmessage'] ?? 'none';
$btnkeyboard = null;
if ($btnmessage != 'none' && $btnmessage != '') {
    $btn_text = broadcast_resolve_btn_text($btnmessage, $info['btntextmessage'] ?? '', $datatextbot);
    $action_map = broadcast_btn_action_map();
    $callback = ($broadcast_id > 0)
        ? ('bc_' . $broadcast_id . '_' . $btnmessage)
        : ($action_map[$btnmessage] ?? $btnmessage);
    $btnkeyboard = json_encode([
        'inline_keyboard' => [
            [
                ['text' => $btn_text, 'callback_data' => $callback],
            ],
        ]
    ]);
}
$batch = array_splice($userid, 0, 50);
foreach ($batch as $iduser) {
    if ($info['type'] == "unpinmessage") {
        unpinmessage($iduser->id);
    } elseif ($info['type'] == "sendmessage" or $info['type'] == "xdaynotmessage") {
        $isphoto = (($info['messagemediatype'] ?? 'text') == 'photo') && !empty($info['photoid']);
        if ($isphoto) {
            $photoparams = [
                'chat_id' => $iduser->id,
                'photo' => $info['photoid'],
                'parse_mode' => 'HTML',
            ];
            if ($info['message'] !== null && $info['message'] !== '') {
                $photoparams['caption'] = $info['message'];
            }
            if ($btnkeyboard !== null) {
                $photoparams['reply_markup'] = $btnkeyboard;
            }
            $meesage = telegram('sendphoto', $photoparams);
        } elseif ($btnkeyboard === null) {
            $meesage = sendmessage($iduser->id, $info['message'], null, 'HTML');
        } else {
            $meesage = sendmessage($iduser->id, $info['message'], $btnkeyboard, 'HTML');
        }

        if (is_array($meesage) && empty($meesage['ok']) && (($meesage['description'] ?? '') === "Forbidden: bot was blocked by the user")) {
            $invoicecount = select("invoice", "*", "id_user", $iduser->id, "count");
            $userinfo = select("user", "Balance", "id", $iduser->id, "select");
            if ($invoicecount == 0 and $userinfo['Balance'] == 0) {
                $Id_user = $iduser->id;
                $stmt = $pdo->prepare("DELETE FROM user WHERE id = '$Id_user'");
                $stmt->execute();
            }
        }

        if (is_array($meesage) && !empty($meesage['ok']) && !empty($info['create_campaign_conversation'])) {
            support_record_campaign_message($pdo, (string) $iduser->id, (string) ($info['message'] ?? ''), [
                'id_admin' => $info['campaign_admin_id'] ?? ($info['id_admin'] ?? ''),
                'username' => $info['campaign_admin_username'] ?? '',
            ]);
        }

        if (is_array($meesage) && !empty($meesage['ok']) && (($info['pingmessage'] ?? '') == "yes")) {
            pinmessage($iduser->id, $meesage['result']['message_id']);
        }
    } elseif ($info['type'] == "forwardmessage") {
        $meesage = forwardMessage($info['id_admin'], $info['message'], $iduser->id);
        if ($meesage['ok'] and $info['pingmessage'] == "yes") {
            pinmessage($iduser->id, $meesage['result']['message_id']);
        }
    }
}

file_put_contents('users.json',json_encode($userid,true));
