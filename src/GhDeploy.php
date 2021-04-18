<?php

namespace Zimaldo;


if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

class GhDeploy
{
    private $deployId;
    private $projectPath;
    private $repo;
    private $branch;
    private $token;
    private $updateOnly;
    private $runComposer;
    private $repoName;

    /**
     * @param string $projectPath Current project path (to receive the files in specified branch)
     */
    public function __construct(string $projectPath)
    {
        $token = $_GET['token'] ?? NULL;
        if (!$token) die('Auth missing');

        $this->deployId = uniqid('deploy_');
        $this->token = $token;

        if (!file_exists($projectPath)) die("Project path '$projectPath' don't exists");

        $projectPath = str_replace('/', DS, $projectPath);
        if ($projectPath[strlen($projectPath) - 1] == DS) $projectPath = substr($projectPath, 0, -1);
        $this->projectPath = $projectPath;
        $this->setConfigs($projectPath . DS . 'ghConfig.json');

        $this->getNewConfig();
        $this->setConfigs($projectPath . DS . 'ghConfig.json');


        $zipPath = $projectPath . DS . $this->deployId . '.zip';
        $deployPath = $projectPath . DS . $this->deployId;

        if (!$this->downloadZip($zipPath)) die('Error downloading branch');

        try {
            mkdir("$deployPath-temp");
        } catch (\Exception $th) {
            die("Error making new folder to deploy in '$deployPath-temp', check server permissions");
        }

        if (!$this->unzip($zipPath, "$deployPath-temp"));
        unlink($zipPath);

        rename("$deployPath-temp" . DS . "{$this->repoName}-{$this->branch}", $deployPath);
        rmdir("$deployPath-temp");

        if ($this->runComposer && file_exists($deployPath . DS . 'composer.json')) {
            shell_exec("cd \"$this->runComposer\" && composer install");
            shell_exec("cd \"$this->runComposer\" && composer update");
        }

        $result = $this->updateOnly ? $this->update($projectPath, $this->deployId) : $this->clone($projectPath);


        die("Success!");
    }

    private function setConfigs(string $configFile)
    {
        if (!file_exists($configFile)) die("Config file ('ghConfig.json'), not found in project path");


        $configArr = json_decode(file_get_contents($configFile), true);
        extract($configArr);

        if (strpos($repo, '://') !== false) die('$repo can\'t be a URL, use "user/repository" instead');
        if ($repo[0] == '/') $repo = substr($repo, 1);
        if ($repo[strlen($repo) - 1] == '/') $repo = substr($repo, 0, -1);
        $x = explode('/', $repo);
        if (count($x) > 2) die('Unrecognized $repo');
        $repoName = $x[1];

        $this->repo = $repo;
        $this->branch = $branch;
        $this->repoName = $repoName;
        $this->updateOnly = $updateOnly;
        $this->runComposer = $runComposer;
    }

    private function getNewConfig()
    {
        $configFile = $this->projectPath . DS . 'ghConfig.json';
        $bkpConfigFile = $this->projectPath . DS . 'ghConfig.json.' . $this->deployId;
        if (file_exists($configFile)) rename($configFile, $bkpConfigFile);
        $r = $this->request(
            "https://raw.githubusercontent.com/{$this->repo}/{$this->branch}/ghConfig.json",
            ["Authorization: token {$this->token}"],
            NULL,
            $configFile
        );
        if ($r['code'] >= 400) {
            if (file_exists($bkpConfigFile)) rename($bkpConfigFile, $configFile);
            die("Config file ('ghConfig.json'), not found in github repository");
        }

        if (file_exists($bkpConfigFile)) unlink($bkpConfigFile);
    }

    private function clone()
    {
        $deployPath = $this->projectPath . DS . $this->deployId;
        mkdir("$deployPath-bkp");
        if (!$this->moveFolderFiles(
            $this->projectPath,
            "$deployPath-bkp",
            [
                $this->projectPath . DS . $this->deployId,
                $this->projectPath . DS . "{$this->deployId}-bkp"
            ]
        )) die("Error moving project files to backup");
        if (!$this->moveFolderFiles($deployPath, $this->projectPath)) die("Error moving deployed files to project");
        rmdir($deployPath);
        return true;
    }

    private function update()
    {
        $deployPath = $this->projectPath . DS . $this->deployId;
        mkdir("$deployPath-bkp");
        $replaceLista = $this->recursiveScanDir($deployPath);
        $lista = [];
        foreach ($replaceLista as $val) {
            $lista[] = $this->str_replace_first($deployPath, $this->projectPath, $val);
        }
        if (!$this->moveFolderFiles($this->projectPath, "$deployPath-bkp", $lista, true)) die("Error moving project files to backup");
        if (!$this->moveFolderFiles($deployPath, $this->projectPath)) die("Error moving deployed files to project");
        rmdir($deployPath);
        return true;
    }

    private function recursiveScanDir(string $dir)
    {
        if ($dir[strlen($dir) - 1] == DS) $dir = substr($dir, 0, -1);
        $scan = scandir($dir);
        $files = [];
        foreach ($scan as $file) {
            if (in_array($file, ['.', '..'])) continue;
            $file = $dir . DS . $file;
            if (is_dir($file)) $files = array_merge($files, $this->recursiveScanDir($file));
            else $files[] = $file;
        }
        return $files;
    }

    private function strStartsWith(string $str, $with)
    {
        if (!is_array($with)) $with = [$with];
        foreach ($with as $w) {
            if (substr($str, 0, strlen($w)) === $w) return true;
        }
        return false;
    }

    private function str_replace_first($from, $to, $content)
    {
        $from = '/' . preg_quote($from, '/') . '/';

        return preg_replace($from, $to, $content, 1);
    }

    private function moveFolderFiles(string $sourceFolder, string $targetFolder, array $excludes = [], $includes = false)
    {
        //Se includes for true, os arquivos em excludes sao os unicos que devem ser movidos
        $files = $this->recursiveScanDir($sourceFolder);
        // var_dump($files);
        $excludes = array_merge($excludes, ['.', '..']);
        // var_dump($excludes);
        foreach ($files as $fileDir) {
            if ((!$includes && $this->strStartsWith($fileDir, $excludes)) || ($includes && !$this->strStartsWith($fileDir, $excludes))) continue;


            $targetDir = $this->str_replace_first($sourceFolder, $targetFolder, $fileDir);
            $x = explode(DS, $targetDir);
            $newD = '';
            for ($i = 0; $i < count($x) - 1; $i++) {
                $newD .= ($newD != '' || $newD == '' && $x[0] == '' ? DS : '') . $x[$i];
                if (!file_exists($newD)) mkdir($newD);
            }

            if (file_exists($fileDir) && !rename($fileDir, $targetDir)) return false;
        }
        return true;
    }

    private function unzip(string $zipFile, string $targetFolder)
    {
        $zip = new \ZipArchive;
        $res = $zip->open($zipFile);
        if ($res === TRUE) {
            $zip->extractTo($targetFolder);
            $zip->close();
            return true;
        } else {
            return false;
        }
    }

    private function downloadZip(string $savePath)
    {
        $r = $this->request(
            "https://github.com/{$this->repo}/archive/refs/heads/{$this->branch}.zip",
            ["Authorization: token {$this->token}"],
            NULL,
            $savePath
        );

        if ($r['code'] >= 400) {
            die("Download fail (code: {$r['code']}). Check token, repository and verify token repo permissions");
        }

        return true;
    }

    private function request(string $url, array $headers = NULL, mixed $body = NULL, string $saveIn = NULL, string $method = 'GET')
    {
        $fp = NULL;

        if ($saveIn) {
            try {
                $fp = fopen($saveIn, 'w+');
            } catch (\Exception $e) {
                return false;
            }
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => TRUE,
        ]);
        if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        if ($saveIn) curl_setopt($ch, CURLOPT_FILE, $fp);
        else curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

        $res = curl_exec($ch);

        if ($saveIn) fclose($fp);

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code >= 400 && $saveIn) {
            unlink($saveIn);
        }

        return [
            'url' => $url,
            'body' => $res ?? '',
            'code' => $code
        ];
    }
}
