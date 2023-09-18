<?php namespace RUB\NEDCaptchaExternalModule;

/**
 * A helper class that holds settings info for this external module.
 */
class CaptchaSettings 
{
    public $debug;
    public $show_errors;
    public $always;
    public $type;
    // Image CAPTCHA
    public $length;
    public $textColor;
    public $backgroundColor;
    public $noiseColor;
    public $noiseDensity;
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
        $this->show_errors = ($this->isProject && $module->getProjectSetting("nedcaptcha_showerrors") == true);

        // Get or generate secrets to encrypt payloads.
        $this->blobSecret = $module->getSystemSetting("nedcaptcha_blobsecret");
        $this->blobHmac = $module->getSystemSetting("nedcaptcha_blobhmac");

        // Only in the context of a project.
        if ($this->isProject) {
            $this->always = $this->getValue("nedcaptcha_always", false);
            $this->type = $this->getValue("nedcaptcha_type", "math");
            // Image.
            $this->length = $this->getValue("nedcaptcha_length", 6, true);
            $angles = array("none" => 0, "slight" => 7, "medium" => 11, "strong" => 15);
            $this->angle = $angles[$this->getValue("nedcaptcha_anglevariation", "medium")];
            $densities = array("off" => 0, "low" => 0.6, "medium" => 1, "high" => 1.5);
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

}
