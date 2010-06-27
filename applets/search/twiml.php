<?php
//Needed because we're unserializing objects from the session, and this class
//isn't loaded.
include_once(APPPATH.'models/vbx_device.php');

//this should help with namespace problems
define(SESSION_KEY, AppletInstance::getInstanceId());
session_start();

//Note: in reverse chronological order for simpler condition tests, 'return' is
//used to stop further processing

//User in session means the call is being connected or it's been routed to
//voicemail. Both actions requre the user object.
if(isset($_SESSION[SESSION_KEY]['user'])){
    //turn the user back into an object
    $user = unserialize($_SESSION[SESSION_KEY]['user']);

    //save the voicemail
    if(isset($_REQUEST['RecordingUrl'])){
        OpenVBX::addVoiceMessage(
            $user,
            $_REQUEST['CallGuid'],
            $_REQUEST['Caller'],
            $_REQUEST['Called'],
            $_REQUEST['RecordingUrl'],
            $_REQUEST['Duration']);

        return;

    //dialstatus means an attempt to connect
    } elseif(isset($_REQUEST['DialStatus'])) {
        if('answered' == $_REQUEST['DialStatus']){
            $response = new Response();
            $response->addHangup();
            $response->Respond();

            return;
        }
        return connect(new Response(), $user)->Respond();

    }

    //something's wrong, let the caller know and start over.
    return searchPrompt(errorResponse(new Response()))->Respond();
}

//Users in session means the caller should have selected a user.
if(isset($_SESSION[SESSION_KEY]['users'])) {
    //turn users back into an array of user objects
    $users = unserialize($_SESSION[SESSION_KEY]['users']);

    $index = $_REQUEST['Digits'];
    //dialing '0' will restart the process
    if("0" == $index){
        return searchPrompt(addMessage(new Response(), 'restartMessage', 'Starting over.'))->Respond();
    //check if the input is valid (note an offset of 1).
    } elseif(!isset($users[$index - 1])){
        //send the menu again, the entry wasn't found
        return promptMenu(addMessage(new Response(), 'invalidMessage', 'Not a valid selection.'), $users)->Respond();
    }

    //the caller selected a user, unset the 'users' session var
    unset($_SESSION[SESSION_KEY]['users']);

    //get the selected user
    $user = $users[$index - 1];

    //store the user in session and set the device index to 0
    $_SESSION[SESSION_KEY]['user'] = serialize($user);
    $_SESSION[SESSION_KEY]['number'] = 0;

    $response = new Response();
    $response->addSay("Connecting you to {$user->first_name} {$user->last_name}");
    return connect($response, $user)->Respond();
}

//Digits in the request, with no users or user is session means the caller is
//searching for a user.
if(isset($_REQUEST['Digits'])){
    //find any matching users
    $users = getMatches($_REQUEST['Digits']);
    if(0 == count($users)){
        return searchPrompt(addMessage(new Response(), 'nomatchMessage', 'Sorry, no matches found.'))->Respond();
    }

    //add users to the session so the next request can select one
    $_SESSION[SESSION_KEY]['users'] = serialize($users);
    return promptMenu(addMessage(new Response(), 'menuPrompt', 'Please select a user, or press 0 to search again.'), $users)->Respond();
}

//prompt for search
return searchPrompt(new Response())->Respond();

//Essentially the logic of the dial applet in a different form.
function connect($response, $user)
{
    //get a name to announce the call
    $name = $user->first_name . " " . $user->last_name;
    
    //get the current device id and increment for the next attempt
    $device = $_SESSION[SESSION_KEY]['number']++;

    //try to connect the current device (if it exists)
    if(isset($user->devices[$device])){
        if(!$user->devices[$device]->is_active){
            //just try the next device
            return connect($response, $user);
        }
        //add a dial to the response
        $dial = $response->addDial(array('action' => current_url()));
        //and a number to the dail, so the call can be announced.
        $dial->addNumber($user->devices[$device]->value, array('url' => site_url('twiml/whisper?name='.urlencode($name))));

    //when there are no more devices send to voicemail
    } else {
	$response->append(AudioSpeechPickerWidget::getVerbForValue($user->voicemail, new Say("Please leave a message.")));
	$response->addRecord(array(
            'transcribe' => 'true',
            'transcribeCallback' => site_url('twiml/transcribe') ));
    }

    //return the response so it can be modified
    return $response;
}

//List the search results, and offset the index by 1 because dialing 0 generally
//means something.
function promptMenu($response, $users)
{
    $gather = $response->addGather();
    foreach($users as $index => $user){
        $pos = $index + 1;
        $gather->addSay("Dial $pos for {$user->first_name} {$user->last_name}");
    }
    
    //return the response so it can be modified
    return $response;
}

//Inital prompt.
function searchPrompt($response)
{
    //since this is called on restart, make sure we clear everything
    unset($_SESSION[SESSION_KEY]);

    //add a gather to the rseponse then add the prompt to the gather
    addMessage($response->addGather(), 'searchPrompt', 'Please enter the first few letters of the name, followed by the pound sign.');
    
    //return the response so it can be modified
    return $response;
}

function errorResponse($response)
{
    return addMessage($response, 'errorMessage', 'Sorry, an error occured.');
}

function addMessage($response, $name, $fallback)
{
    $message = AppletInstance::getAudioSpeechPickerValue($name);
    $response->append(
        AudioSpeechPickerWidget::getVerbForValue($message, new Say($fallback)));

    return $response;
}

//Find users that possible match a series of digits.
//TODO: It's likely better to work it the other way, generating a list of
//numbers from the users and looking for a partial match.
function getMatches($digits)
{
    //setup dial pad letters
    $dialpad[0] = array();
    $dialpad[1] = array();
    $dialpad[2] = array('a','b','c');
    $dialpad[3] = array('d','e','f');
    $dialpad[4] = array('g','h','i');
    $dialpad[5] = array('j','k','l');
    $dialpad[6] = array('m','n','o');
    $dialpad[7] = array('p','q','r', 's');
    $dialpad[8] = array('t','u','v');
    $dialpad[9] = array('w','x','y', 'z');

    //start with an empty string
    $words = array('');
    //take each digit
    for($i = 0; $i < strlen($digits); $i++){
        $digit = $digits[$i];
        $oldWords = $words;
        $words = array();
        //and add all possible letters to every previous combination
        foreach($oldWords as $word){
            foreach($dialpad[$digit] as $letter){
                $words[] = $word . $letter;
            }
        }
    }

    if(count($words) == 0){
        return array();
    }

    //TODO: This looks a little messy - should find a better way to query for
    //all matching names. Or again, work it the other way, building a number
    //for each user.

    //create crazy query
    foreach($words as $word){
        $search[] = "first_name LIKE '%$word%' OR last_name LIKE '%$word%'";
    }

    $query = implode(" OR ", $search);

    //get all users that are possible matches
    $users = OpenVBX::getUsers(array($query . 'AND true = ' => 1));
    return $users;
}