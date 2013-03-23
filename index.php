<?php

require_once 'vendor/autoload.php';
require_once 'config.php';

class Program
{
    public static $rules = array(
        array(
            'name' => 'Tab-indentation',
            'regex' => '/^ *\t/',
        ),
        array(
            'name' => 'Trailing spaces',
            'regex' => '/[ \t]+$/'
        ),
        array(
            'name' => 'Possible JS console',
            'regex' => '/console\.[a-zA-Z]*\(/',
        ),
        array(
            'name' => 'Possible JS debugger',
            'regex' => '/debugger\;?/',
        ),
        array(
            'name' => 'Possible incorrect indent',
            'regex' => '/^(?:(?: {1,3})\S|(?:(?:(?: {4})+(?: {1,3}?)\S)))/',
        ),
        array(
            'name' => 'Windows line endings',
            'regex' => '/\r\n$/'
        ),
    );

    public static function parsePatch($patchText)
    {
        $foo = str_replace("\n\r", "\n", $patchText);
        $lines = explode("\n", $foo);
        $check = 'diff --git';
        foreach ($lines as $index => $line) {
            if (substr($line, 0, strlen($check)) === $check) {
                $startOfDiff = $index;
            }
        }
        $lines = array_splice($lines, $startOfDiff);
        return self::parse($lines);
    }

    public static function parse(array $lines)
    {
        $newLines = array();
        $hunk = array();
        foreach ($lines as $index => $line) {
            if (substr($line, 0, 1) === '+' && substr($line, 0, 3) !== "+++") {
                $newLines[] = substr($line, 1, strlen($line) - 1);
            }
        }
        return self::runRules($newLines);
    }

    public static function runRules(array $lines)
    {
        $txt = '';
        $errors = false;
        foreach (self::$rules as $rule) {
            $output = false;
            foreach ($lines as $line) {
                if (preg_match($rule['regex'], $line)) {
                    if (!$output) {
                        $txt .= "The following lines match the rule `" . $rule['name'] . "`:\n";
                        $output = true;
                        $errors = true;
                    }
                    $txt .= '* `+'. $line . "`\n";
                }
            }
            if ($output) {
                $txt .= "\n";
            }
        }

        if ($errors) {
            $txt .= "I'm just a dumb bot. Please let @axyjo know if there are any false positives or if I've missed anything obvious.\n";
        }

        return $txt;
    }
}

//Program::parsePatch();
// Format of config looks like this:
/*$config = array(
    'github' => array(
        'username' => 'USERNAME',
        'password' => 'PASSWORD',
    ),
    'branches' => array('master', 'sidebranch'),
    'users' => array('axyjo'),
    'base_user' => 'tedivm',
    'base_repo' => 'jshrink',
);
*/
$client = new Github\Client();
$client->authenticate($config['github']['username'], $config['github']['password'], Github\Client::AUTH_HTTP_PASSWORD);

$prs = $client->api('pr')->all($config['base_user'], $config['base_repo']);
$user_whitelist = $config['users'];
$branch_whitelist = $config['branches'];

$ignore_shas = array();
if (file_exists('ignore_shas.php')) {
    include 'ignore_shas.php';
}

foreach ($prs as $pr) {
    $repo_name = $pr['head']['repo']['name'];
    $repo_full_name = $pr['head']['repo']['full_name'];
    $pr_sha = $pr['head']['sha'];
    $head = $pr['head']['ref'] . '(' . $pr_sha . ')';
    if (in_array($pr['base']['ref'], $branch_whitelist) && in_array($pr['head']['user']['login'], $user_whitelist)) {
        // echo '---' . PHP_EOL;
        // echo 'Title: ' . $pr['title'] . PHP_EOL;
        // echo 'From: ' . $repo_full_name . '@' . $head . PHP_EOL;
        // echo 'To: @' . $pr['base']['ref'] . PHP_EOL;

        if (!in_array($pr_sha, $ignore_shas)) {
            $process = curl_init($pr['patch_url']);
            curl_setopt($process, CURLOPT_HEADER, 1);
            curl_setopt($process, CURLOPT_USERPWD, $config['github']['username'] . ":" . $config['github']['password']);
            curl_setopt($process, CURLOPT_TIMEOUT, 30);
            curl_setopt($process, CURLOPT_RETURNTRANSFER, 1);
            $return = curl_exec($process);

            $warnings = Program::parsePatch($return);
            if (strlen(trim($warnings)) !== 0) {
                $resp = $client->api('issue')->comments()->create($config['base_user'], $config['base_repo'], $pr['number'], array('body' => $warnings));
                $client->api('repo')->statuses()->create($pr['head']['user']['login'], $repo_name, $pr_sha, array('state' => 'error', 'target_url' => $resp['html_url']));
            };

            $ignore_shas[] = $pr_sha;
        }
    }
}

$ignore_shas_php = '<?php $ignore_shas = ' . var_export($ignore_shas, true) . ';';
file_put_contents('ignore_shas.php', $ignore_shas_php);
