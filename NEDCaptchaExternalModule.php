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
        $result = array("success" => false, "error" => null);

        if ($is_postback) {
            // Verify response.
            $response = $_POST["{$this->PREFIX}-response"];
            $blob = $_POST["{$this->PREFIX}-blob"];
            $ip = $_SERVER["REMOTE_ADDR"];

            $result = $this->validate($response, $ip, $blob);
        }

        $debug = "<script>console.log('Debug Info');</script>";

        if (!$result["success"]) {
            // Pepare CAPTCHA.
            $captcha = "Just enter 'test' and you are good.";

            // Prepare output.
            $blob = $this->toSecureBlob(array(
                "project_id" => $project_id,
                "instrument" => $instrument,
                "event_id" => $event_id,
                "repeat_instance" => $repeat_instance,
                "expected" => "test"
            ));
            $template = file_get_contents(dirname(__FILE__)."/ui.html");
            $replace = array(
                "{PREFIX}" => $this->PREFIX,
                "{SURVEYTITLE}" => $GLOBALS["title"],
                "{INSTRUCTIONS}" => $this->settings->intro,
                "{LABEL}" => $this->settings->label,
                "{SUBMIT}" => $this->settings->submit,
                "{FAILMSG}" => $this->settings->failmsg,
                "{FAILMSGDISPLAY}" => "none",
                "{BLOB}" => $blob,
                "{CAPTCHA}" => $captcha,
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
    function validate($response, $ip, $blob) 
    {
        $result = array (
            "success" => false,
            "error" => null,
        );

        do {
            // Retrieve transferred data.
            $data = $this->fromSecureBlob($blob);
            if ($data == null) {
                $result["error"] = "Failed to decrypt blob.";
                break;
            }
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

/**
 * A helper class that holds settings info for this external module.
 */
class CaptchaSettings 
{
    public $debug;
    public $type;
    public $intro;
    public $label;
    public $submit;
    public $failmsg;
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

        // Only in the context of a project
        if ($this->isProject) {
            $this->type = $this->getValue("nedcaptcha_type", "math");
            $this->intro = $this->getValue("nedcaptcha_intro", null);
            $this->label = $this->getValue("nedcaptcha_label", $this->type == "image" ? "Please type in the text exactly as displayed" : "Please solve this math problem:");
            $this->submit = $this->getValue("nedcaptcha_submit", "Submit");
            $this->failmsg = $this->getValue("nedcaptcha_failmsg", "Validation failed. Please try again.");
        }
    }

    private function getValue($name, $default) 
    {
        $value = $this->m->getProjectSetting($name);
        return strlen($value) ? $value : $default;
    }
} // CaptchaSettings