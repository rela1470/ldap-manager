<?php

$outputArray = [];

$person = $_REQUEST['person'];
$groups = $_REQUEST['groups'];

$account  = $_REQUEST['account'];
$password = $_REQUEST['password'];


$addGroups = explode(',', $groups);

// ldap バインドを使用する
$ldapdn  = 'hoge';     // ldap rdn あるいは dn
$ldaprdn  = 'hoge' . $ldapdn;   // ldap rdn あるいは dn
$ldappass = 'hoge';  // パスワード

$ldap_user = 'hoge';
$ldap_newpass = 'hoge';

// ここLDAP設定により変わるので適当ですが、ちゃんと設定しないと動きません
$ldap_fulldn = 'uid='.$ldap_user. ','. $ldapdn;

//ここで管理するグループ
$targetGroups = [

];

//承認できるメンバー
$adminUsers = [
];

//グループ初期化
foreach ($addGroups as $id => $addGroup) {
    if (! $addGroup) {
        unset($addGroups[$id]);
        continue;
    }
    if (! in_array($addGroup, $targetGroups)) {
        finallyOutput("{$addGroup}はこのツールでは変更できません", true);
    }
}

if (! $account && ! $password) {
    require 'login.html';
    exit;
}


if (! in_array($account, $adminUsers)) {
    finallyOutput("{$account}さんには権限がありません", true);
}


output('LDAPサーバーに接続しています');

// ldap サーバーに接続する
$ldapconn = ldap_connect("localhost")
 or finallyOutput("接続できませんでした", true);

if ($ldapconn) {
	
	
	ldap_set_option($link_id, LDAP_OPT_PROTOCOL_VERSION, 3) or finallyOutput("protocol versionを設定できませんでした", true);
	ldap_set_option($link_id, LDAP_OPT_REFERRALS, 0) or finallyOutput("referralsを設定出来ませんでした", true);


	//管理者チェック
    output('承認者の権限チェックをしています');
    $ldapbind = ldap_bind($ldapconn, "uid={$account},cn=users,{$ldapdn}", $password);

    // バインド結果を検証する
    if ($ldapbind) {
        output("承認者の権限チェックに成功しました");
    } else {
        finallyOutput("承認者の権限チェックに失敗しました。パスワードが違うか権限がありません", true);
    }

    //操作用アカウントでログインし直し
    output('管理アカウントでログインしています');
    $ldapbind = ldap_bind($ldapconn, $ldaprdn, $ldappass);

    // バインド結果を検証する
    if ($ldapbind) {
        output("管理アカウントログインに成功しました");
    } else {
        finallyOutput("管理アカウントログインに失敗しました。再度やり直してください", true);
    }


    // ユーザを検索するワード（ここでは後方一致）
    output("処理対象者: {$person}");

    //グループ一覧取得
    $filter = "(cn=0*)";
    $searchID = ldap_search($ldapconn, $ldapdn, $filter);

    // 上記で見つかったID達を取得
    $entries = ldap_get_entries( $ldapconn, $searchID );

    //所属しているグループの検索
    $nowGroups = [];
	foreach ($entries as $entry) {
	    if (! isset($entry['memberuid'])) continue;
	    if (in_array($person, $entry['memberuid'])) {
            $nowGroups[$entry['cn'][0]] = $entry['cn'][0];
        }
    }

    $groupText = implode(', ', $nowGroups);
    output("現在所属しているグループ: {$groupText}");


    //グループ初期化
    output('指定されなかったグループから脱退');
    foreach ($targetGroups as $targetGroup) {
        if (! in_array($targetGroup, $nowGroups)) continue;
        if (in_array($targetGroup, $addGroups)) continue;

        $dn = "cn={$targetGroup}, cn=groups," . $ldapdn;
        output("{$targetGroup}から脱退");
        ldap_mod_del($ldapconn, $dn, ['memberuid' => $person, 'member' => "uid={$person},cn=users,{$ldapdn}"]);
    }

    //グループ追加
    output('指定されたグループに加入');
    foreach ($addGroups as $targetGroup) {
        if (in_array($targetGroup, $nowGroups)) continue;
        $dn = "cn={$targetGroup}, cn=groups," . $ldapdn;
        output("{$targetGroup}に加入");
        ldap_mod_add($ldapconn, $dn, ['memberuid' => $person, 'member' => "uid={$person},cn=users,{$ldapdn}"]);
    }


    //全件表示
    /*
     for ($i=0; $i<$entries["count"]; $i++) {
        // to show the attribute displayName (note the case!)
        echo $entries[$i]["uid"][0].":".$entries[$i]["mail"][0] . ":" . $entries[$i]["userpassword"][0] . ":" . $entries[$i]["dn"] . "<br />";
    }*/

    // close
    ldap_close($ldapconn);
    finallyOutput('コネクション終了');

} else {
    finallyOutput('コネクション失敗', true);
}

function finallyOutput($text = '', $isError = false) {
    global $outputArray;
    global $groups;
    global $account;
    global $person;

    if ($text) output($text);
    require 'log.html';

    if (! $isError) {
        sendSlack($account, $person, $groups, '#hoge');
        sendSlack($account, $person, $groups, "@{$person}");
    }
    exit;
}

function output($text, $isEnd = false) {
    global $outputArray;
    $outputArray[] =  "[" . date("Y/m/d H:i:s") . "] " . $text . "<br/>";
}

function sendSlack($account, $person, $groups, $channel)
{
    // Webhook URL
    $url = "hoge";

    // メッセージ
    $message = array(
        "channel" => $channel,
        "username" => "秘書(権限管理担当)",
        "attachments" => array(
            array(
                "text" => "{$account}さんが{$person}さんを以下の権限に変更しました!\n{$groups}"
            )
        )
    );

    // メッセージをjson化
    $message_json = json_encode($message);

    // payloadの値としてURLエンコード
    $message_post = "payload=" . urlencode($message_json);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $message_post);
    curl_exec($ch);
    curl_close($ch);
}
