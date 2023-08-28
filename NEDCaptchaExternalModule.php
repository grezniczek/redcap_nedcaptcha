<?php namespace RUB\NEDCaptchaExternalModule;

use ExternalModules\AbstractExternalModule;

require_once "classes/Color.php";
require_once "classes/CaptchaSettings.php";
require_once "classes/CaptchaGenerator.php";

/**
 * ExternalModule class for Patient Finder.
 */
class NEDCaptchaExternalModule extends AbstractExternalModule {
    
    private $settings;
    private $cipher = "AES-256-CBC";

    #region Hooks

    /**
     * Hook function that is executed for every survey page in projects where the module is enabled.
     */
    function redcap_survey_page_top($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1)  {
        // Skip the CAPTCHA if a record is already defined or it is set to inactive.
        if (!empty($record)) return false;
        
        $this->settings = new CaptchaSettings($this);
        if ($this->settings->type == "none") return false;

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
            if (!empty($captcha->error) && $this->settings->show_errors) {
                print "<div class=\"alert alert-danger\"><b>nedCAPTCHA error:</b> {$captcha->error}</div>";
                $this->exitAfterHook();
            }
            // Type of input - use number for math CAPTCHAs.
            $type = $this->settings->type == "math" ? "number" : "text";

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
            $action = APP_PATH_SURVEY_FULL. "?" . $_SERVER["QUERY_STRING"]; 
            $isMobile = isset($GLOBALS["isMobileDevice"]) && $GLOBALS["isMobileDevice"];
            $logo = "";
            if (is_numeric($GLOBALS["logo"])) {
                //Set max-width for logo (include for mobile devices)
                $logo_width = $isMobile ? '300' : '600';
                // Get img dimensions (local file storage only)
                $thisImgMaxWidth = $logo_width;
                $styleDim = "max-width:{$thisImgMaxWidth}px;";
                list ($thisImgWidth, $thisImgHeight) = \Files::getImgWidthHeightByDocId($GLOBALS["logo"]);
                if (is_numeric($thisImgHeight)) {
                    $thisImgMaxHeight = round($thisImgMaxWidth / $thisImgWidth * $thisImgHeight);
                    if ($thisImgWidth < $thisImgMaxWidth) {
                        // Use native dimensions.
                        $styleDim = "width:{$thisImgWidth}px;max-width:{$thisImgWidth}px;height:{$thisImgHeight}px;max-height:{$thisImgHeight}px;";
                    } else {
                        // Shrink size.
                        $styleDim = "width:{$thisImgMaxWidth}px;max-width:{$thisImgMaxWidth}px;height:{$thisImgMaxHeight}px;max-height:{$thisImgMaxHeight}px;";
                    }
                }
                $logo .= "<div style=\"padding:10px 0 0;\"><img id=\"survey_logo\" onload=\"try{reloadSpeakIconsForLogo()}catch(e){}\" " .
                    "src=\"".APP_PATH_SURVEY."index.php?pid={$project_id}&doc_id_hash=".\Files::docIdHash($GLOBALS["logo"]) .
                    "&__passthru=".urlencode("DataEntry/image_view.php")."&s={$GLOBALS["hash"]}&id={$GLOBALS["logo"]}\" alt=\"" . 
                    js_escape($GLOBALS["lang"]["survey_1140"])."\" title=\"".js_escape($GLOBALS["lang"]["survey_1140"]) .
                    "\" style=\"max-width:{$logo_width}px;$styleDim\"></div>";
            }
            $mobile = $isMobile ? "_mobile" : "";
            $template = file_get_contents(dirname(__FILE__)."/ui{$mobile}.html");
            $replace = array(
                "{PREFIX}" => $this->PREFIX,
                "{GUID}" => self::GUID(),
                "{TYPE}" => $type,
                "{LOGO}" => $logo,
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
            // We need to redirect so we get regular behavior
            $url = APP_PATH_SURVEY_FULL. "?" . $_SERVER["QUERY_STRING"];
            $this->framework->redirectAfterHook($url, true);
        }
    }

    #endregion

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

    /**
     * Generates a GUID in the format xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx.
     */
    public static function GUID() 
    {
        if (function_exists('com_create_guid') === true) {
            return strtolower(trim(com_create_guid(), '{}'));
        }
        return strtolower(sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535)));
    }

}
