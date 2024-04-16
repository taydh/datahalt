<?php
namespace Taydh\TeleQuery;

class FileSystemConnector
{
    private $baseDir;

    public function __construct ( $baseDir )
    {
        $this->baseDir = realpath($baseDir);
    }

    public function isAcceptedPath ( $realPath )
    {
        return strpos($realPath, $this->baseDir) == 0;
    }

    public function removeFirstDirSeparatorChar ( $path )
    {
        if (strpos($path, DIRECTORY_SEPARATOR) === 0
            || strpos($path, '\\') === 0
            || strpos($path, '/') === 0) {
            $path = substr($path, 1);
        }

        return $path;
    }

    public function readFile ( $filepath, $options )
    {
        $filepath = $this->removeFirstDirSeparatorChar($filepath);
        $filepath = realpath($this->baseDir . DIRECTORY_SEPARATOR . $filepath);

        if ($filepath === false) return false; 
        if (!$this->isAcceptedPath($filepath)) throw new \Exception('Path is not accepted');

        $item = ['name' => basename($filepath)];

        foreach ($options as $opt) {
            if ($opt == 'size') $item[$opt] = filesize($filepath);
            if ($opt == 'text') $item[$opt] = file_get_contents($filepath);
            if ($opt == 'content') $item[$opt] = base64_encode(file_get_contents($filepath));
            if ($opt == 'fullpath') $item[$opt] = $filepath;
        }

        return $item;
    }

    public function readDir ( $dirpath, $options )
    {
        $dirpath = $this->removeFirstDirSeparatorChar($dirpath);
        $dirpath = realpath($this->baseDir . DIRECTORY_SEPARATOR . $dirpath);

        if ($dirpath === false) return false; 
        if (!$this->isAcceptedPath($dirpath)) throw new \Exception('Path is not accepted');

        $result = [];
        $lastChar = $dirpath[strlen($dirpath) - 1];
        if (!in_array($lastChar, [DIRECTORY_SEPARATOR,'\\','/']) ) $dirpath = $dirpath . DIRECTORY_SEPARATOR;

        if ($handle = opendir($dirpath)) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry != "." && $entry != "..") {
                    $item = [ 'name' => $entry ];

                    foreach ($options as $opt) {
                        if ($opt == 'directory') $item[$opt] = is_dir($dirpath . $entry);
                        if ($opt == 'size') $item[$opt] = filesize($dirpath . $entry);
                    }

                    $result[] = $item;
                }
            }
            closedir($handle);
        }

        return $result;
    }
}