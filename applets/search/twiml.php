<?php
include_once(APPPATH.'models/vbx_device.php');
define(SESSION_KEY, AppletInstance::getInstanceId());
session_start();

//first time through
if(!isset($_REQUEST['Digits']) AND !isset($_REQUEST['DialStatus'])){
    //prompt for search
    return searchPrompt(new Response());
}

//second time through
if(!isset($_SESSION[SESSION_KEY]['users']) AND !isset($_REQUEST['DialStatus'])){
    //find and list users
    $users = getMatches($_REQUEST['Digits']);
    if(0 == count($users)){
        $response = new Response();
        $response->append(new Say("Sorry, no matches found."));
        return searchPrompt($response);
    }

    $_SESSION[SESSION_KEY]['users'] = serialize($users);
    return promptMenu(new Response(), $users);

//third time through
} elseif(isset($_SESSION[SESSION_KEY]['users'])) {
    //dial selected user
    $index = $_REQUEST['Digits'];
    $users = unserialize($_SESSION[SESSION_KEY]['users']);
    if("0" == $index){
        //0 resets the process
        $response = new Response();
        $response->addSay("Starting over.");
        return searchPrompt($response);
    } elseif(!isset($users[$index - 1])){
        //send the menu again, the entry wasn't found
        $response = new Response();
        $response->addSay("Please select a user.");
        return promptMenu($response, $users);
    }

    unset($_SESSION[SESSION_KEY]['users']);
    
    $user = $users[$index - 1];

    $_SESSION[SESSION_KEY]['user'] = serialize($user);
    $_SESSION[SESSION_KEY]['number'] = 0;

    $response = new Response();

    $response->addSay("Connecting you to {$user->first_name} {$user->last_name}");
    connect($response, $user);
    return;
//try all devices
} elseif(isset($_SESSION[SESSION_KEY]['user'])){
    $user = unserialize($_SESSION[SESSION_KEY]['user']);

    if(isset($_SESSION[SESSION_KEY]['voicemail'])){
        OpenVBX::addVoiceMessage(
            $user->id,
            $_REQUEST['CallGuid'],
            $_REQUEST['Caller'],
            $_REQUEST['Called'],
            $_REQUEST['RecordingUrl'],
            $_REQUEST['Duration']);
    } elseif(isset($_REQUEST['DialStatus'])) {
        if('answered' == $_REQUEST['DialStatus']){
            $response = new Response();
            $response->addHangup();
            $response->Respond();
            return;
        }
        connect(new Response(), $user);
        return;
    }
}

//if nothing responded, somethign is wrong
$response = new Response();
$response->addSay("Something went wrong.");
$response->Respond();

function connect($response, $user)
{
    $name = $user->first_name . " " . $user->last_name;
    //get the current device id and increment for the next time
    $device = $_SESSION[SESSION_KEY]['number']++;
    //try to connect the current device

    if(isset($user->devices[$device])){
        if(!$user->devices[$device]->is_active){
            //just try the next device
            return connect($response, $user);
        }
        $dial = $response->addDial(array('action' => current_url()));
        $dial->addNumber($user->devices[$device]->value, array('url' => site_url('twiml/whisper?name='.urlencode($name))));

    //when there are no more devices send to voicemail
    } else {
        $_SESSION[SESSION_KEY]['voicemail'] = true;
	$response->append(AudioSpeechPickerWidget::getVerbForValue($user->voicemail, new Say("Please leave a message.")));
	$response->addRecord(array(
            'transcribe' => true,
            'transcribeCallback' => site_url('twiml/transcribe') ));
    }
    $response->Respond();
}

function promptMenu($response, $users)
{
    $gather = $response->addGather();
    foreach($users as $index => $user){
        $pos = $index + 1;
        $gather->addSay("Dial $pos for {$user->first_name} {$user->last_name}");
        //print_r($user);
    }

    $response->Respond();
}

function getMatches($digits)
{
    //setup dial pad letters
    $dialpad[2] = array('a','b','c');
    $dialpad[3] = array('d','e','f');
    $dialpad[4] = array('g','h','i');
    $dialpad[5] = array('j','k','l');
    $dialpad[6] = array('m','n','o');
    $dialpad[7] = array('p','q','r', 's');
    $dialpad[8] = array('t','u','v');
    $dialpad[9] = array('w','x','y', 'z');

    //find all combinations
    //TODO: likely better to work it the other way, generating a
    //list from the users and looking for a partial match.
    $words = array('');
    //take each digit
    for($i = 0; $i < strlen($digits); $i++){
        $digit = $digits[$i];
        $oldWords = $words;
        $words = array();
        //and add all if its digits to every previous possibility
        foreach($oldWords as $word){
            foreach($dialpad[$digit] as $letter){
                $words[] = $word . $letter;
            }
        }
    }
    //TODO: well now, this looks a little messy should find a better way to
    //query for all matching names. Or again, work it the other way, building
    //a number for each user

    //create crazy query
    foreach($words as $word){
        $search[] = "first_name LIKE '%$word%' OR last_name LIKE '%$word%'";
    }
    $query = implode(" OR ", $search);

    //get all users that are possible matches
    $users = OpenVBX::getUsers(array($query . 'AND true = ' => 1));
    return $users;
}

function searchPrompt($response)
{
    //make sure we clear everything
    unset($_SESSION[SESSION_KEY]);
    //grab the search prompt
    $prompt = AppletInstance::getAudioSpeechPickerValue('searchPrompt');
    //play the search prompt
    $response->addGather()
        ->append(AudioSpeechPickerWidget::getVerbForValue($prompt,
        new Say('Please enter the first few letters of the name, followed by the pound sign.')));
    //get some digits
    $response->Respond();
    return;
}