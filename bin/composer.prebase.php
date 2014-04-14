<?php
/**
 * creates data folder and its var and media folders
 * moves the magento root folder into the data folder as a backup and then recreates the magento root folder
 *
 * @todo convert to OOP
 */
$composerConfig = json_decode(file_get_contents('composer.json'), TRUE);

if (TRUE === empty($composerConfig)) {
    echo 'composer.json not found' . PHP_EOL;
    exit(2);
}
$target = json_decode(file_get_contents($composerConfig['extra']['magento-installer-config']['target-file']), TRUE);
if (TRUE === empty($target)) {
    echo 'target.json not found' . PHP_EOL;
    exit(2);
}

if (FALSE === isset($composerConfig['extra']['magento-installer-config'])) {
    echo 'magento-installer-config in composer.json not found' . PHP_EOL;
    exit(2);
}

$isRelease            = $target['target'] !== 'development';
$gitReleaseBranchName = $composerConfig['extra']['magento-installer-config']['release-prefix-branch-name'];
$magentoRootDir       = rtrim($composerConfig['extra']['magento-root-dir'], '/');
$dataDir              = $composerConfig['extra']['magento-installer-config']['directories']['data'];
$dataSubDirs          = $composerConfig['extra']['magento-installer-config']['directories']['data-sub-dirs'];

if (TRUE === $isRelease) {
    $currentGitBranchName = trim(shell_exec('git rev-parse --abbrev-ref HEAD'));
    if (TRUE === empty($currentGitBranchName)) {
        echo 'Failed to figure out the current git branch name' . PHP_EOL;
        exit(2);
    }
    if (FALSE === stristr($currentGitBranchName, $gitReleaseBranchName)) {
        echo 'You are creating a release but your branch name is ' . $currentGitBranchName . ' but must start with ' . $gitReleaseBranchName . PHP_EOL;
        exit(2);
    }
}

if (FALSE === is_dir($dataDir)) {
    mkdir($dataDir, 0751);
    echo "Created dir $dataDir" . PHP_EOL;
}

foreach ($dataSubDirs as $subDir) {
    $dir = $dataDir . DIRECTORY_SEPARATOR . $subDir;
    if (FALSE === is_dir($dir)) {
        if (FALSE === mkdir($dir, 0775, TRUE)) {
            echo 'Failed to create dir:' . $dir . PHP_EOL;
            exit(2);
        }
    }
}
/**
 * move magento root folder to data folder for backup reasons only
 */
if (TRUE === is_dir($magentoRootDir)) {
    $newRootDir = $dataDir . DIRECTORY_SEPARATOR . $magentoRootDir . '_' . date('Y-m-d_His');
    if (FALSE === rename($magentoRootDir, $newRootDir)) {
        echo 'Failed to move directory from :' . $magentoRootDir . ' to: ' . $newRootDir . PHP_EOL;
        exit(2);
    }
    mkdir($magentoRootDir, 0775, TRUE);
    touch($magentoRootDir . DIRECTORY_SEPARATOR . '.gitempty');
}
