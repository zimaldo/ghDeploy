<?php

namespace Zimaldo;

if (!defined('DS')) define('DS', DIRECTORY_SEPARATOR);

class GhDeploy
{

    /**
     * @param string $repo user/repository from github
     * @param string $projectPath Current project path (to receive the files in specified branch)
     * @param string $branch Branch to download and deploy
     * @param bool $composerInstall If true run 'composer install' (if composer.json exists in repo)
     */
    public function __construct(string $repo, string $projectPath, string $branch = 'master', bool $composerInstall = true)
    {
        $token = $_GET['token'] ?? NULL;
        if (!$token) die('Auth missing');

        if (!file_exists($projectPath)) die("Project path '$projectPath' don't exists");

        if (strpos($repo, '://') !== false) die('$repo can\'t be a URL, use "user/repository" instead');
        if ($repo[0] == '/') $repo = substr($repo, 1);
        if ($repo[strlen($repo) - 1] == '/') $repo = substr($repo, 0, -1);
        $x = explode('/', $repo);
        if (count($x) > 2) die('Unrecognized $repo');

        $repoName = $x[1];

        $projectPath = str_replace('/', DS, $projectPath);
        if ($projectPath[strlen($projectPath) - 1] == DS) $projectPath = substr($projectPath, 0, -1);

        $deployId = uniqid('deploy_');

        $zipPath = $projectPath . DS . $deployId . '.zip';
        $deployPath = $projectPath . DS . $deployId;

        if (!$this->downloadZip($repo, $token, $branch, $zipPath)) die('Error downloading branch');

        try {
            mkdir("$deployPath-temp");
        } catch (\Exception $th) {
            die("Error making new folder to deploy in '$deployPath-temp', check server permissions");
        }

        if (!$this->unzip($zipPath, "$deployPath-temp"));
        unlink($zipPath);

        rename("$deployPath-temp" . DS . "$repoName-$branch", $deployPath);
        rmdir("$deployPath-temp");

        if ($composerInstall && file_exists($deployPath . DS . 'composer.json')){
            shell_exec("cd \"$deployPath\" && composer install");
            shell_exec("cd \"$deployPath\" && composer update");
        }

        mkdir("$deployPath-bkp");
        if (!$this->moveFolderFiles($projectPath, "$deployPath-bkp", [$deployId, "$deployId-bkp"])) die("Error moving project files to backup");
        if (!$this->moveFolderFiles($deployPath, $projectPath)) die("Error moving deployed files to project");
        rmdir($deployPath);
        die("Success!");
    }

    private function moveFolderFiles(string $sourceFolder, string $targetFolder, array $excludes = [])
    {
        $files = scandir($sourceFolder);
        $excludes = array_merge($excludes, ['.', '..']);
        foreach ($files as $file) {
            if (in_array($file, $excludes)) continue;
            if (!rename($sourceFolder . DS . $file, $targetFolder . DS . $file)) return false;
        }
        return true;
    }

    private function unzip(string $zipFile, $targetFolder)
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

    private function downloadZip(string $repo, string $token, string $branch, string $savePath)
    {
        $url = "https://github.com/$repo/archive/refs/heads/$branch.zip";

        try {
            $fp = fopen($savePath, 'w+');
        } catch (\Exception $th) {
            return false;
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => TRUE,
            CURLOPT_HTTPHEADER => [
                "Authorization: token $token"
            ],
            CURLOPT_FILE => $fp
        ]);

        curl_exec($ch);

        fclose($fp);

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code >= 400) {
            unlink($savePath);
            die("Download fail (code: $code). Check token, repository and verify token repo permissions");
        }

        return true;
    }
}
