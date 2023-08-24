<?php namespace RUB\NEDCaptchaExternalModule;

class CaptchaGenerator
{
    public $challenge;
    public $expected;
    public $error = null;
    

    private $operators = array ("+", "-", "x");
    private $font;

    public function __construct(CaptchaSettings $settings, $expected = null)
    {
        $this->font = dirname(__FILE__)."../fonts/AnonymousPro-Regular.ttf";
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
        
        try {
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
        catch (\Throwable $ex) {
            $this->error = $ex->getMessage();
            return false;
        }
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
        
        try {
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
        catch (\Throwable $ex) {
            $this->error = $ex->getMessage();
            return false;
        }
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
