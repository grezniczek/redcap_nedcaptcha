# nedCAPTCHA - Manual Testing Procedure

Version 1 - 2020-04-12

## Prerequisites

- A project with a public survey.
- nedCAPTCHA is enabled for this project.
- The reCAPTCHA feature must be **disabled** for the project.
- No other external modules should be enabled, except those with which this module's interaction should be tested.

## Test Procedure

1. Using an admin account, configure the module: 
   - Set _CAPTCHA type_ to "None".
   - Set display texts to "Introduction", "Label", "Button", and "Fail message".
1. Go to the _Survey Distribution Tools_ page and click on _Open public survey_.
1. Verify the following:
   - The survey is displayed. None of the nedCAPTCHA elements are shown.
1. Change the module configuration:
   - Set _CAPTCHA type_ to "Simple Math Problem".
   - Set _Complexity_ to "Simple".
   - Set _Minimum_ to "5" and _Maximum_ to "8".
   - Set _Text color_ to "#FF0000" (red).
   - Set _Background color_ to "240,220,220" (light red).
1. Go to the _Survey Distribution Tools_ page and click on _Open public survey_.
1. Verify the following:
   - "Introduction" is shown under the survey title.
   - "Label" is shown as the label.
   - "Button" is shown as the button label.
   - The math problem is shown in red text on light red background.
   - The values of the operands are in the range 5 to 8.
1. Press "F5" (browser refresh) several times and verify that the numbers in the problem stay in the range 5 to 8.
1. Give a false resulte (e.g. "1") and press "Button".
1. Verify the following:
   - A new problem is presented.
   - The message "Fail message" is displayed.
1. Give a correct result and verify the following:
   - The normal survey is now displayed.
   - The survey can be filled out and submitted.
1. Change the module configuration:
   - Set _CAPTCHA type_ to "Distorted Text Image".
   - Set _Length of the challenge text_ to "4".
   - Turn on _Keep the same challenge for retries_.
   - Set _Rotation of individual letters_ to "Medium".
   - Set _Noise intensity_ to "Medium".
   - Set _Noise color_ to "#00FF00" (green).
1. Go to the _Survey Distribution Tools_ page and click on _Open public survey_.
1. Verify the following:
   - A four-letter CAPTCHA is displayed, red on light red background with green noise.
1. Enter a false answer and verify the following:
   - The "Fail message" is displayed.
   - The CAPTCHA text stays constant, only its appearance varies slightly (letter rotation and background noise).
1. Enter the correct text, click the "Button" and verify the following:
   - The normal survey is now displayed.
   - The survey can be filled out and submitted.
1. Change the module configuration:
   - Set _CAPTCHA type_ to "Custom".
   - Set _Challenge and response pairs_ to "Question=Answer".
1. Go to the _Survey Distribution Tools_ page and click on _Open public survey_.
1. Verify the following:
   - "Question" is displayed.
1. Enter a wrong solution, click "Button", and verify the following:
   - The "Fail message" is displayed.
1. Enter "Answer", click the "Button" and verify the following:
   - The normal survey is now displayed.
   - The survey can be filled out and submitted.

Done.

## Reporting Errors

Before reporting errors:
- Make sure there is no interference with any other external module by turning off all others and repeating the tests.
- Check if you are using the latest version of the module. If not, see if updating fixes the issue.

To report an issue:
- Please report errors by opening an issue on [GitHub](https://github.com/grezniczek/redcap_nedcaptcha/issues) or on the community site (please tag @gunther.rezniczek). 
- Include essential details about your REDCap installation such as **version** and platform (operating system, PHP version).
- If the problem occurs only in conjunction with another external module, please provide its details (you may also want to report the issue to that module's authors).
