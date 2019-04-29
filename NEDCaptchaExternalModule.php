<?php
namespace RUB\NEDCaptchaExternalModule;


use ExternalModules\AbstractExternalModule;

/**
 * ExternalModule class for Patient Finder.
 */
class NEDCaptchaExternalModule extends AbstractExternalModule {
    
    private $settings;

    function __construct()
    {
        // Need to call parent constructor first!
        parent::__construct();
        // Initialize settings.
        $this->settings = new CaptchaSettings($this);
    }

    /**
     * Hook function that is executed for every survey page in projects where the module is enabled.
     */
    function redcap_survey_page_top($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1) 
    {
        // Skip the CAPTCHA if a record is already defined or it is set to inactive.
        if (!empty($record) || $this->settings->type == "none") return false;
        

        // Has the user already solved a CAPTCHA during her session? (Always show when debugging)
        if (!$this->settings->debug && isset($_SESSION["{$this->PREFIX}-success"]) && $_SESSION["{$this->PREFIX}-success"] === true) return false;
        
        // Determine whehter this is a public survey.
        $survey_id = $GLOBALS["Proj"]->forms[$instrument]["survey_id"];
        $public_hash = \Survey::getSurveyHash($survey_id, $event_id);
        if ($public_hash !== $survey_hash) {
            // This is not the public survey, so we do not show the CAPTCHA.
            return false;
        }
        
        // Is this a post-back from a CAPTCHA?
        $is_postback = $_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["{$this->PREFIX}-response"]) && isset($_POST["{$this->PREFIX}-blob"]);
        $result = array("success" => false, "error" => null, "challenge" => null);
        $failMsgDisplay = "none";

        if ($is_postback) {
            // Verify response.
            $response = strtolower(trim($_POST["{$this->PREFIX}-response"]));
            $blob = $_POST["{$this->PREFIX}-blob"];

            $result = $this->validate($response, $blob);
            if (!$result["success"]) $failMsgDisplay = "block";
        }

        $debug = ""; //"<script>console.log('Debug Info');</script>";

        if (!$result["success"]) {
            // Pepare CAPTCHA.
            $captcha = new CaptchaGenerator($this->settings, $this->settings->reuse ? $result["challenge"] : null);

            // Prepare output.
            $blob = $this->toSecureBlob(array(
                "project_id" => $project_id,
                "instrument" => $instrument,
                "event_id" => $event_id,
                "repeat_instance" => $repeat_instance,
                "timestamp" => (new \DateTime())->getTimestamp(),
                "expected" => $captcha->expected,
            ));
            $label = $this->settings->type == "custom" && !strlen($this->settings->label) ? $captcha->challenge : $this->settings->label;
            $challenge = $this->settings->type == "custom" && !strlen($this->settings->label) ? "" : $captcha->challenge;
            // Need to specify the action and add _startover or else REDCap will swallow the survey instructions, as we arrive with a POST and not a GET.
            $action = APP_PATH_SURVEY_FULL . "?" . $_SERVER["QUERY_STRING"] . (strpos($_SERVER["QUERY_STRING"], "__startover") ? "" : "&__startover");
            $template = file_get_contents(dirname(__FILE__)."/ui.html");
            $replace = array(
                "{PREFIX}" => $this->PREFIX,
                "{ACTION}" => $action,
                "{SURVEYTITLE}" => $GLOBALS["title"],
                "{INSTRUCTIONS}" => $this->settings->intro,
                "{LABEL}" => $label,
                "{SUBMIT}" => $this->settings->submit,
                "{FAILMSG}" => $this->settings->failmsg,
                "{FAILMSGDISPLAY}" => $failMsgDisplay,
                "{BLOB}" => $blob,
                "{CAPTCHA}" => $challenge,
                "{DEBUG}" => $this->settings->debug ? $debug : ""
            );
            print str_replace(array_keys($replace), array_values($replace), $template);
            // No further processing (i.e. do not let REDCap render the survey page).
            $this->exitAfterHook();
        }
        else {
            $_SESSION["{$this->PREFIX}-success"] = true;
        }
    }

    private $cipher = "AES-256-CBC";

    /**
     * Helper function to package an array into an encrytped blob (base64-encoded).
     * $data is expected to be an associative array.
     */
    private function toSecureBlob($data)
    {
        $this->checkKeys();
        $jsonData = json_encode($data);
        $key = base64_decode($this->settings->blobSecret);
        $ivLen = openssl_cipher_iv_length($this->cipher);
        $iv = openssl_random_pseudo_bytes($ivLen);
        $aesData = openssl_encrypt($jsonData, $this->cipher, $key, OPENSSL_RAW_DATA, $iv);
        $hmac = hash_hmac('sha256', $aesData, $this->settings->blobHmac, true);
        $blob = base64_encode($iv.$hmac.$aesData);
        return $blob;
    }

    /**
     * Helper function to decode an encrypted data blob.
     * Retruns an associative array or null if there was a problem.
     */
    private function fromSecureBlob($blob) 
    {
        $this->checkKeys();
        $raw = base64_decode($blob);
        $key = base64_decode($this->settings->blobSecret);
        $ivlen = openssl_cipher_iv_length($this->cipher);
        $iv = substr($raw, 0, $ivlen);
        $blobHmac = substr($raw, $ivlen, 32);
        $aesData = substr($raw, $ivlen + 32);
        $jsonData = openssl_decrypt($aesData, $this->cipher, $key, OPENSSL_RAW_DATA, $iv);
        $calcHmac = hash_hmac('sha256', $aesData, $this->settings->blobHmac, true);
        // Only return data if the hashes match.
        return hash_equals($blobHmac, $calcHmac) ? json_decode($jsonData, true) : null;
    }

    private function checkKeys() 
    {
        if (!strlen($this->settings->blobSecret)) {
            $this->settings->blobSecret = $this->genKey(32);
            $this->setSystemSetting("nedcaptcha_blobsecret", $this->settings->blobSecret);
        }
        if (!strlen($this->settings->blobHmac)) {
            $this->settings->blobHmac = $this->genKey(32);
            $this->setSystemSetting("nedcaptcha_blobhmac", $this->settings->blobHmac);
        }
    }

    /**
     * Determines, whether the CAPCHA was solved.
     */
    function validate($response, $blob) 
    {
        $result = array (
            "success" => false,
            "error" => null,
            "challenge" => null,
        );

        do {
            // Retrieve transferred data.
            $data = $this->fromSecureBlob($blob);
            if ($data == null) {
                $result["error"] = "Failed to decrypt blob.";
                break;
            }
            // Check timestamp expiration (max. 5 minutes).
            if ((new \DateTime())->getTimestamp() - $data["timestamp"] > 300) {
                $result["error"] = "Timeout.";
                break;
            }

            // Keep note of challenge for eventual reuse.
            $result["challenge"] = $data["expected"];
            // Verify project id.
            $project_id = $data["project_id"];
            if ($project_id != $GLOBALS["project_id"]) {
                $result["error"] = "Project ID mismatch.";
                break;
            }
            // Check answer.
            if ($data["expected"] == $response) {
                $result["success"] = true;
                break;
            }
        } while (false);

        return $result;
    }

    private function genKey($keySize) 
    {
        $key = openssl_random_pseudo_bytes($keySize);
        return base64_encode($key);
    }

} // NEDCaptchaExternalModule


class CaptchaGenerator
{
    public $challenge;
    public $expected;
    public $error = null;
    

    private $operators = array ("+", "-", "x");
    private $font;

    public function __construct(CaptchaSettings $settings, $expected = null)
    {
        $this->font = dirname(__FILE__)."/fonts/AnonymousPro-Regular.ttf";
        $this->expected = $expected;
        switch ($settings->type) {
            case "math":
                $this->math($settings);
                break;
            case "image":
                $this->image($settings);
                break;
            case "custom":
                $this->custom($settings);
                break;
        }
    }

    /**
     * Prepares a custom CAPTCHA.
     * 
     * @param CaptchaSettings $settings
     */
    private function custom($settings) 
    {
        // Check that challenge-response pairs are available.
        $n = count($settings->custom);
        if ($n == 0) {
            $this->error = "No custom challenge-response pairs defined.";
            return false;
        }
        // Pick a random one.
        $index = mt_rand(0, $n - 1);
        $this->challenge = $settings->custom[$index]["challenge"];
        $this->expected = trim(strtolower($settings->custom[$index]["response"]));
    }

    /**
     * Prepares a math CAPTCHA.
     * 
     * @param CaptchaSettings $settings
     */
    private function math($settings) 
    {
        $complex = $settings->complexity == "complex";
        // Build the problem.
        $nTerms = $complex ? 2 : 1;
        $op = array();
        $vals = array();
        $problem = "";
        for ($i = 0; $i <= $nTerms; $i++) {
            $vals[$i] = mt_rand($settings->minvalue, $settings->maxvalue);
        }
        for ($i = 0; $i < $nTerms; $i++) {
            $op[$i] = $this->operators[$complex ? mt_rand(0, 2) : 0];
        }
        // Necessary to add brackets? (for mixed mode problems)
        $result = -1;
        $ops = join("", $op);
        // Do not do double multiplications as the numbers can become big.
        if ($ops == "xx") $ops = "x+";
        // Build problem and calculate result.
        // Ensure non-negative results when subtractions are present.
        switch ($ops) {
            case "+":
            case "++":
                $problem = join(" + ", $vals);
                $result = array_sum($vals);
                break;
            case "-": 
                sort($vals);
                $problem =  "{$vals[1]} - {$vals[0]}";
                $result = $vals[1] - $vals[0];
                break;
            case "--":
                sort($vals);
                $result = $vals[2] - $vals[1] - $vals[0];
                if ($result < 0) {
                    $vals[2] = $vals[1] + $vals[0] + mt_rand($settings->minvalue, $settings->maxvalue);
                    $result = $vals[2] - $vals[1] - $vals[0];
                }
                $problem = "{$vals[2]} - {$vals[1]} - {$vals[0]}";
                break;
            case "+-":
                if ($vals[1] > $vals[2]) {
                    $problem = "{$vals[0]} + {$vals[1]} - {$vals[2]}";
                    $result = $vals[0] + $vals[1] - $vals[2];
                }
                else {
                    $problem = "{$vals[0]} + {$vals[2]} - {$vals[1]}";
                    $result = $vals[0] + $vals[2] - $vals[1];
                }
                break;
            case "-+":
                if ($vals[0] > $vals[1]) {
                    $problem = "{$vals[0]} - {$vals[1]} + {$vals[2]}";
                    $result = $vals[0] - $vals[1] + $vals[2];
                }
                else {
                    $problem = "{$vals[1]} - {$vals[0]} + {$vals[2]}";
                    $result = $vals[1] - $vals[0] + $vals[2];
                }
                break;
            case "x":
            case "xx":
                $problem = join(" × ", $vals);
                $result = 1;
                foreach ($vals as $v) $result = $result * $v;
                break;
            case "x+":
                $problem = "{$vals[0]} × ( {$vals[1]} + {$vals[2]} )";
                $result = $vals[0] * ($vals[1] + $vals[2]);
                break;
            case "+x":
                $problem = "( {$vals[0]} + {$vals[1]} ) × {$vals[2]}";
                $result = ($vals[0] + $vals[1]) * $vals[2];
                break;
            case "x-":
                if ($vals[1] > $vals[2]) {
                    $problem = "{$vals[0]} × ( {$vals[1]} - {$vals[2]} )";
                    $result = $vals[0] * ($vals[1] - $vals[2]);
                }
                else {
                    $problem = "{$vals[0]} × ( {$vals[2]} - {$vals[1]} )";
                    $result = $vals[0] * ($vals[2] - $vals[1]);
                }
                break;
            case "-x":
                if ($vals[0] > $vals[1]) {
                    $problem = "( {$vals[0]} - {$vals[1]} ) × {$vals[2]}";
                    $result = ($vals[0] - $vals[1]) * $vals[2];
                }
                else {
                    $problem = "( {$vals[1]} - {$vals[0]} ) × {$vals[2]}";
                    $result = ($vals[1] - $vals[0]) * $vals[2];
                }
                break;
        }
        $this->expected = "{$result}";
        $this->challenge = $settings->debug ? "{$problem} = {$result}" : $this->asImage($problem, $settings, 30);
    }

    private function asImage($challenge, $settings, $height = 50) 
    {
        $nChars = strlen($challenge);
        // Calculate the width based on the number of characters.
        $width = $height / 2 * $nChars;

        // Set the fontsize smaller than the height. 60% is good for the font used.
        $fontSize = $height * 0.7;
        
        // Create the image.
        if (false === ($img = imagecreate($width, $height)))
        {
            $this->error = "Cannot initialize GD image stream ({$width}, {$height}).";
            return false;
        }
        $bc = imagecolorallocate($img, $settings->backgroundColor->R, $settings->backgroundColor->G, $settings->backgroundColor->B);
        $tc = imagecolorallocate($img, $settings->textColor->R, $settings->textColor->G, $settings->textColor->B);

        // Fill the background.
        imagefill($img, 0, 0, $bc);
        
        // Create a textbox.
        if (false === ($box = imagettfbbox($fontSize, 0, $this->font, $challenge))) {
            $this->error = "Cannot create text [measuring].";
            return false;
        }
        // Draw the text.
        $x = ($width - $box[4]) / 2;
        $y = ($height - $box[5]) / 2.2;
        if (false === imagettftext($img, $fontSize, 0, $x, $y, $tc, $this->font, $challenge)) {
            $this->error = "Cannot create text [drawing].";
            return false;
        }
        // Get the image.
        ob_start();
        imagepng($img);
        $png = ob_get_contents();
        ob_end_clean();
        imagedestroy($img);
        // Create the img tag.
        $data = base64_encode($png);
        return "<img src=\"data:image/png;base64,{$data}\" alt=\"CAPTCHA\" />";
    }

    private function image($settings) 
    {
        // Set the expected result.
        if (empty($this->expected)) $this->expected = self::generateChallenge($settings->length);
        
        $challenge = $this->generateImage($this->expected, $settings, 80);
        if ($challenge !== false) $this->challenge = $challenge;
    }


    private function generateImage($challenge, $settings, $height = 80)
    {
        $nChars = strlen($challenge);
        // Calculate the width based on the number of characters.
        $width = $height / 1.6 * $nChars;

        // Set the fontsize smaller than the height. 60% is good for the font used.
        $fontSize = $height * 0.6;
        
        // Create the image.
        if (false === ($img = imagecreate($width, $height)))
        {
            $this->error = "Cannot initialize GD image stream ({$width}, {$height}).";
            return false;
        }
        $bc = imagecolorallocate($img, $settings->backgroundColor->R, $settings->backgroundColor->G, $settings->backgroundColor->B);
        $tc = imagecolorallocate($img, $settings->textColor->R, $settings->textColor->G, $settings->textColor->B);
        $nc = imagecolorallocate($img, $settings->noiseColor->R, $settings->noiseColor->G, $settings->noiseColor->B);

        // Fill the background.
        imagefill($img, 0, 0, $bc);
        // Draw random dots.
        for ($i = 0; $i < $width * $height * 0.1 * $settings->noiseDensity; $i++) {
            imagefilledellipse($img, mt_rand(0, $width), mt_rand(0, $height), 1, 1, $nc);
        }
        // Create a textbox.
        if (false === ($box = imagettfbbox($fontSize, 0, $this->font, $challenge))) {
            $this->error = "Cannot create text [measuring].";
            return false;
        }
        // Draw the text.
        $charWidth = ($width * 0.85) / $nChars;
        $y = ($height - $box[5]) / 2.2;
        for ($i = 0; $i < strlen($challenge); $i++) {
            $x = $width * 0.075 + $charWidth * $i;
            // Vary the angle.
            $a = mt_rand($settings->angle * -1, $settings->angle);
            if (false === imagettftext($img, $fontSize, $a, $x, $y, $tc, $this->font, substr($challenge, $i, 1))) {
                $this->error = "Cannot create text [drawing].";
                return false;
            }
        }
        // Draw random lines.
        for ($i = 0; $i < $width * 0.3 * $settings->noiseDensity; $i++) {
            imageline($img, mt_rand(0, $width), mt_rand(0, $height), mt_rand(0, $width), mt_rand(0, $height), $nc);	
        }
        // Get the image.
        ob_start();
        imagepng($img);
        $png = ob_get_contents();
        ob_end_clean();
        imagedestroy($img);
        // Create the img tag.
        $data = base64_encode($png);
        return "<img src=\"data:image/png;base64,{$data}\" alt=\"CAPTCHA\" />";
    }


    /**
     * Creates the challenge string for the captcha.
     *
     * @param  int     $length  The length of the challenge string to be generated.
     * @return string  The challenge string.
     */
    private static function generateChallenge($length)
    {
        // Pool of useable characters; optimized for readability
        $pool = "23456789bcdfghjkmnpqrstvwxz";
        $poolLastIndex = strlen($pool) - 1;
        $challenge = "";
        for ($i = 0; $i < $length; $i++) {
            $challenge .= substr($pool, mt_rand(0, $poolLastIndex), 1);
        }
        return $challenge;
    }


}


/**
 * A helper class that holds settings info for this external module.
 */
class CaptchaSettings 
{
    public $debug;
    public $type;
    // Image CAPTCHA
    public $length;
    public $textColor;
    public $backgroundColor;
    public $noiseColor;
    public $angle;
    public $reuse;
    // Math CAPTCHA
    public $complexity;
    public $minvalue;
    public $maxvalue;
    // Custom CAPTCHA
    public $custom;
    // Display
    public $intro;
    public $label;
    public $submit;
    public $failmsg;
    // Helpers
    public $blobSecret;
    public $blobHmac;
    public $isProject;

    private $m;

    function __construct($module) 
    {
        $this->isProject = isset($GLOBALS["project_id"]);
        $this->m = $module;
        $this->debug = $module->getSystemSetting("nedcaptcha_globaldebug") || ($this->isProject && $module->getProjectSetting("nedcaptcha_debug"));

        // Get or generate secrets to encrypt payloads.
        $this->blobSecret = $module->getSystemSetting("nedcaptcha_blobsecret");
        $this->blobHmac = $module->getSystemSetting("nedcaptcha_blobhmac");

        // Only in the context of a project.
        if ($this->isProject) {
            $this->type = $this->getValue("nedcaptcha_type", "math");
            // Image.
            $this->length = $this->getValue("nedcaptcha_length", 6, true);
            $angles = array("none" => 0, "slight" => 7, "medium" => 11, "strong" => 15);
            $this->angle = $angles[$this->getValue("nedcaptcha_anglevariation", "medium")];
            $densities = array("off" => 0, "low" => 0.6, "medium" => 1, "high" => 1.8);
            $this->noiseDensity = $densities[$this->getValue("nedcaptcha_noisedensity", "medium")];
            $this->reuse = $this->getValue("nedcaptcha_reuse", false);
            $this->noiseColor = Color::Parse($this->getValue("nedcaptcha_noisecolor", "#333333"));
            // Math.
            $this->complexity = $this->getValue("nedcaptcha_complexity", "simple");
            $this->minvalue = $this->getValue("nedcaptcha_minvalue", 1, true);
            $this->maxvalue = $this->getValue("nedcaptcha_maxvalue", 10, true);
            // Image and Math.
            $this->textColor = Color::Parse($this->getValue("nedcaptcha_textcolor", "#800000"));
            $this->backgroundColor = Color::Parse($this->getValue("nedcaptcha_backgroundcolor", "f3f3f3"));
            // Custom.
            $customRaw = explode("\n",trim($this->getValue("nedcaptcha_custom", "")));
            $custom = array();
            foreach ($customRaw as $line) {
                $items = explode("=", $line);
                if (count($items) == 2) {
                    array_push($custom, array ("challenge" => trim($items[0]), "response" => trim($items[1])));
                }
            }
            $this->custom = $custom;
            // Display.
            $this->intro = $this->getValue("nedcaptcha_intro", null);
            $this->label = $this->getValue("nedcaptcha_label", "");
            if (!strlen($this->label)) {
                switch($this->type) {
                    case "image":
                        $this->label = "Please type in the text exactly as displayed";
                        break;
                    case "math":
                        $this->label =  "Please solve this math problem:";
                        break;
                }
            }
            $this->submit = $this->getValue("nedcaptcha_submit", "Submit");
            $this->failmsg = $this->getValue("nedcaptcha_failmsg", "Validation failed. Please try again.");
        }
    }

    private function getValue($name, $default, $isNumeric = false) 
    {
        $value = $this->m->getProjectSetting($name);
        if ($isNumeric) {
            $value = is_numeric($value) ? $value * 1 : $default;
        }
        return strlen($value) ? $value : $default;
    }

} // CaptchaSettings

class Color 
{
    public $R;
    public $G;
    public $B;
    public $A = 1;

    public function __construct($r = 255, $g = 255, $b = 255, $a = 1.0)
    {
        $this->R = $r;
        $this->G = $g;
        $this->B = $b;
        $this->A = $a;
    }

    /**
     * Parses a color value in the formats r,g,b[,a] or #rrggbb[aa] into a Color.
     * 
     * @param  string $input    The input to parse.
     * @return Color|false      The corresponding color, or false if parsing failed.
     */
    public static function Parse($input)
    {
        if (strpos($input, ",")) {
            // RegEx for r,g,b[,a] format.
            $re = '/\s*(?\'r\'\d+)\s*,\s*(?\'g\'\d+)\s*,\s*(?\'b\'\d+)\s*(,\s*(?\'a\'(1|0\.?\d*)))?/m';
            preg_match_all($re, $input, $matches, PREG_SET_ORDER, 0);
            if (count($matches) == 1) {
                $r = $matches[0]["r"] * 1;
                $g = $matches[0]["g"] * 1;
                $b = $matches[0]["b"] * 1;
                $a = isset($matches[0]["a"]) ? $matches[0]["a"] * 1 : 1.0;
                $color = new Color($r, $g, $b, $a);
                return $color;
            }
       }
        else {
            // RegEx for #rrggbb[aa] format.
            $re = '/#?(?\'r\'[0-9a-fA-F]{2})(?\'g\'[0-9a-fA-F]{2})(?\'b\'[0-9a-fA-F]{2})(?\'a\'[0-9a-fA-F]{2})?/m';
            preg_match_all($re, $input, $matches, PREG_SET_ORDER, 0);
            if (count($matches) == 1) {
                $r = hexdec($matches[0]["r"]);
                $g = hexdec($matches[0]["g"]);
                $b = hexdec($matches[0]["b"]);
                $a = isset($matches[0]["a"]) ? hexdec($matches[0]["a"]) : 255;
                $a = $a / 255;
                $color = new Color($r, $g, $b, $a);
                return $color;
            }
        }        
        return false;
    }
}
