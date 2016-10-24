<?php 
        $ci =& get_instance();
        $ci->load->model('vbx_incoming_numbers');
        
        try {
                $numbers = $ci->vbx_incoming_numbers->get_numbers();
        }
        catch (VBX_IncomingNumberException $e) {
                log_message('Incoming numbers exception: '.$e->getMessage.' :: '.$e->getCode());
                $numbers = array();
        }
        $callerId = AppletInstance::getValue('callerId', null); 
?>

<div class="vbx-applet">
	<h2>Directory Search</h2>
        <p>Callers can search for users by dialing a few letters form the user's
        first or last name. The can press pound when done, or simply wair for 5
        seconds.

        <h2>Caller ID</h2>
            <div class="vbx-full-pane">
                <fieldset class="vbx-input-container">
                    <select class="medium" name="callerId">
                        <option value="">Caller's Number</option>
                        <?php if(count($numbers)) foreach($numbers as $number): $number->phone = normalize_phone_to_E164($number->phone); ?>
                                        <option value="<?php echo $number->phone; ?>"<?php echo $number->phone == $callerId ? ' selected="selected" ' : ''; ?>><?php echo $number->name; ?></option>
                        <?php endforeach; ?>
                    </select>
                </fieldset>
            </div>

        <h3>Search Prompt</h3>
        <p>When the caller reaches this menu they will hear this prompt:</p>
	<?php echo AppletUI::audioSpeechPicker('searchPrompt'); ?>

        <h3>Menu Prompt</h3>
        <p>The caller can select a user from the menu, or dial 0 to try again.
        Before the user menu is played, play this prompt:</p>
        <?php echo AppletUI::audioSpeechPicker('menuPrompt'); ?>

        <h3>No Matches</h3>
        <p>Select a message to play when no users are found, before the process
        is restarted.</p>
        <?php echo AppletUI::audioSpeechPicker('nomatchMessage'); ?>

        <h3>Restart Search</h3>
        <p>The caller dialed 0 to restart the search, customize this message.</p>
        <?php echo AppletUI::audioSpeechPicker('restartMessage'); ?>

        <h3>Invalid Selection</h3>
	<p>Customize a specific message about the invalid selection.</p>
        <?php echo AppletUI::audioSpeechPicker('invalidMessage'); ?>
        
        <h3>Error Message</h3>
        <p>Pick a message to notify the caller an error occurred before the
        process is restarted.</p>
        <?php echo AppletUI::audioSpeechPicker('errorMessage'); ?>
</div>
