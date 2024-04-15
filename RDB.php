<?php

class RDB
{
    protected $fd = null;
    public $filename;
    public $delimiter;
    public $key_delimiter;
    public $chmod;
    const MAX_KEY_LENGTH = 1024;
    const DEBUG = false;
    static $OBJECTS = false;
    protected $select_pipe = null;
    protected $select_matches = null;
    protected $select_case_sensitive = false;
    protected $select_size = 0;
    protected $select_mode = 0;

    const SELECT_HASH = 0;
    const SELECT_KEYS = 1;
    const SELECT_VALUES = 2;
    /**
     * RDB constructor.
     * @param $filename
     * @param int $chmod
     * @param string $delimiter
     * @param string $key_delimiter
     * @throws Exception
     */
    function __construct($filename, $chmod = 0644, $delimiter = "\n", $key_delimiter = "\t")
    {
        $this->fd = fopen($filename, file_exists($filename) ? "r+" : "w+");
        if ($this->fd === FALSE)
            throw new Exception("Can't open file $filename");
        $this->chmod = $chmod;
        $this->filename = $filename;
        $this->delimiter = $delimiter;
        $this->key_delimiter = $key_delimiter;
        $this->chmod();
    }

    static function encode($data)
    {
        return rawurlencode($data);
    }

    static function decode($data)
    {
        return rawurldecode($data);
    }

    public function chmod()
    {
        chmod($this->filename, $this->chmod);
    }

    public function reset()
    {
        $this->lock(true);
        ftruncate($this->fd, 0);
        $this->lock(false);
    }

    function seek_end()
    {
        fseek($this->fd, 0, SEEK_END);
        return ftell($this->fd);
    }

    // Unbelievable, but just fseek sometimes didn't work
    // Could be old PHP, but it was a big problem unless this double check with ftell was in use
    function seek_set($pos, $strict = 0)
    {
        if (ftell($this->fd) == $pos)
            return ($pos);

        $end = false;
        for ($i = 0; $i < 10; $i++)
        {
            fseek($this->fd, 0, SEEK_SET);
            fseek($this->fd, $pos, SEEK_SET);
            if (ftell($this->fd) == $pos)
                return ($pos);

            // Ok, we couldn't seek to the right position, maybe it's outside file size?
            if ($end === false)
                $end = $this->seek_end();
            if ($pos > $end)
            {
                switch ($strict)
                {
                    case 0:
                        // By default - stop at the current EOF
                        $pos = $end;
                        break;

                    case 1:
                        // Returns error
                        return false;

                    case 2:
                        // Very strict
                        die("Can't seek to $pos: outside of file size\n");

                    case 3141592653:
                        $this->seek_end();
                        // Expand our file to new size, this is weird functionality, but....
                        fwrite($this->fd, str_repeat("\0", $pos - $end));
                        break;

                    default:
                        die("Unknown seek strict mode: " . var_export($strict, true) . "\n");
                }
            }
        }

        return (false);
    }

    function lock($ex = false)
    {
        flock($this->fd, $ex ? LOCK_EX : LOCK_SH);
    }

    function unlock()
    {
        flock($this->fd, LOCK_UN);
    }

    public function close()
    {
        $this->unlock();
        fclose($this->fd);
    }


    // Find position of the record close to the middle of a chunk
    // Returns such position or false if the chunk is one single record
    function middle($start_pos, $end_pos)
    {
        if (self::DEBUG)
            echo "$start_pos/$end_pos\n";
        $chunk_length = $end_pos - $start_pos;
        $delimiter_length = strlen($this->delimiter);
        if ($chunk_length <= $delimiter_length)
            return false;

        $middle_pos = $start_pos + intval($chunk_length / 2);
        $this->seek_set($middle_pos);
        $str = stream_get_line($this->fd, $end_pos - $middle_pos, $this->delimiter);
        if ($str === false)
        {
            // Shouldn't happen as chunk is not empty, but...
            return false;
        }
        $len = strlen($str) + $delimiter_length;
        $next_pos = $middle_pos + $len;
        if ($next_pos < $end_pos)
        {
            // Start of the record just after the middle and it's not the end, Ok
            return $next_pos;
        }

        // No delimiter in second part, looking for delimiter in a first part between $start_pos and $middle_pos
        $buflen = 10240;
        if ($middle_pos - $start_pos < $buflen)
        {
            // Short ones - just read whole chunk and look for last delimiter
            $this->seek_set($start_pos);
            // delimiter could be split at $middle_pos
            $str = fread($this->fd, $middle_pos - $start_pos + strlen($this->delimiter));
            $offset = strrpos($str, $this->delimiter);
            // No delimiter in first part either
            if ($offset === false)
                return false;
            $pos = $start_pos + $offset + strlen($this->delimiter);
            if ($pos >= $end_pos)
                return false;
            return $pos;
        }

        return $this->middle($start_pos, $middle_pos);
    }

    function read_key($pos)
    {
        $this->seek_set($pos);
        $key = stream_get_line($this->fd, self::MAX_KEY_LENGTH + strlen($this->key_delimiter), $this->key_delimiter);
        return $key;
    }

    function read_pair($pos)
    {
        $key = $this->read_key($pos);
        if ($key === false)
            return false;
        $end_pos = $this->seek_end();
        $pos = $this->seek_set($pos + strlen($key) + strlen($this->key_delimiter));
        $data = stream_get_line($this->fd, $end_pos - $pos, $this->delimiter);
        if ($data === false)
            return false;
        return array($key, $data);
    }

    function read_data($pos)
    {
        $pair = $this->read_pair($pos);
        if ($pair === false)
            return false;
        return $pair[1];
    }

    // Need only -1 or 1 unlike standard strcmp()
    function strcmp($a, $b)
    {
        $x = strcmp($a, $b);
        if ($x < 0)
            return -1;
        if ($x > 0)
            return 1;
        return 0;
    }

    //  Key check, return values:
    // 1 - it's $start_key
    // -1 - less than $start_key
    // 2 - it's $end_key
    // -2 - more than $end_key
    // 0 - somewhere in between
    function probe($key, $start_key, $end_key)
    {
        switch ($this->strcmp($key, $start_key))
        {
            case 0:
                return 1;
            case -1:
                return -1;
        }

        // if $end_key is false - the end is EOF
        switch ($end_key === false ? -1 : $this->strcmp($key, $end_key))
        {
            case 0:
                return 2;
            case 1:
                return -2;
        }
        return 0;
    }

    // Seek for a position with $key, return value:
    // (true, $position) - record found
    // (false, $position) - record not found, should be inserted at $position
    function seek($key)
    {
        $key = substr($key, 0, self::MAX_KEY_LENGTH);
        $start_pos = 0;
        $end_pos = $this->seek_end();
        $start_key = $this->read_key(0);
        $end_key = false;
        while (1)
        {
            $p = $this->probe($key, $start_key, $end_key);
            if (self::DEBUG)
                echo "$key $start_pos/$end_pos $start_key/$end_key : $p\n";
            switch ($p)
            {
                case 1:
                    return array(true, $start_pos);
                case 2:
                    return array(true, $end_pos);
                case -1:
                    return array(false, $start_pos);
                case -2:
                    return array(false, $end_pos);
            }

            // Ok, our key in between $start_key and $end_key
            $middle_pos = $this->middle($start_pos, $end_pos);
            if ($middle_pos === false)
            {
                // No records in between
                if ($end_key === false)
                    return array(false, $end_pos);

                return array(false, $start_pos);
            }

            $middle_key = $this->read_key($middle_pos);
            switch ($x = $this->strcmp($key, $middle_key))
            {
                case 0:
                    return array(true, $middle_pos);
                case -1:
                    $end_pos = $middle_pos;
                    break;
                case 1:
                    $start_pos = $middle_pos;
                    break;
                default:
                    die("UNDEF strcmp result: $x\n");
            }
        }
        return array(false, $start_pos);
    }

    // I/O stuff
    function write($data, $len = false)
    {
        if (!$len)
            $len = strlen($data);
        for ($rest = $len; $rest > 0; $rest -= $wlen)
        {
            $wlen = fwrite($this->fd, $data, $rest);
            fflush($this->fd);
            if ($wlen === FALSE)
                return (FALSE);
        }
        return ($len);
    }

    function read($len)
    {
        $data = "";
        for ($rest = $len; !feof($this->fd) && $rest > 0; $rest -= $wlen)
        {
            $wlen = strlen($str = fread($this->fd, $rest));
            $data .= $str;
        }
        return $data;
    }

    // Move chunk from one position to another
    // We suppose that the file is not huge
    function shift($rpos, $wpos, $len)
    {
        if ($rpos == $wpos || $len == 0)
            return;

        $this->seek_set($rpos);
        $data = $this->read($len);
        $this->seek_set($wpos);
        $this->write($data, $len);
    }

    /**
     * @param $pos
     * @param $old_len
     * @param $data
     * @param bool $new_len
     * @throws Exception
     */
    // Substitute one chunk with new one
    function subst($pos, $old_len, $new_data, $new_len = false)
    {
        if (!$new_len)
            $new_len = strlen($new_data);
        $end = $this->seek_end();

        $rest = $end - $pos - $old_len;
        if ($rest < 0)
            throw new Exception("ILLEGAL SUBST: $pos/$old_len <= $new_len ($new_data)");

        if ($old_len != $new_len)
        {
            $this->shift($pos + $old_len, $pos + $new_len, $rest);
            if ($new_len < $old_len)
                ftruncate($this->fd, $end + $new_len - $old_len);
        }

        if ($new_len > 0)
        {
            $this->seek_set($pos);
            $this->write($new_data, $new_len);
        }
        return;
    }

    // Find the data with $key
    public function get($key)
    {
        $ekey = self::encode($key);
        $this->lock();
        list($ok, $pos) = $this->seek($ekey);
        if ($ok)
            $data = self::decode($this->read_data($pos));
        else
            $data = false;
        $this->unlock();
        return $data;
    }

    // Set the data with $key
    public function put($key, $data)
    {
        if (!$key)
            return false;
        $ekey = self::encode($key);
        $edata = self::encode($data);
        $this->lock(true);
        list($ok, $pos) = $arr = $this->seek($ekey);
        if ($ok === true)
        {
            $pair = $this->read_pair($pos);
            if ($pair === false)
                return false;
            list($ekey, $old_data) = $pair;
            $old_len = strlen($ekey) + strlen($this->key_delimiter) + strlen($old_data) + strlen($this->delimiter);
        }
        else
            $old_len = 0;
        $record = $ekey . $this->key_delimiter . $edata . $this->delimiter;
        $new_len = strlen($record);
        $this->subst($pos, $old_len, $record, $new_len);
        $this->unlock();
    }

    // Delete record
    public function del($key)
    {
        if (!$key)
            return false;
        $ekey = self::encode($key);
        $this->lock(true);
        list($ok, $pos) = $arr = $this->seek($ekey);
        if ($ok === true)
        {
            $pair = $this->read_pair($pos);
            if ($pair === false)
                return false;
            list($ekey, $old_data) = $pair;
            $old_len = strlen($ekey) + strlen($this->key_delimiter) + strlen($old_data) + strlen($this->delimiter);
            $this->subst($pos, $old_len, '', 0);
        }
        $this->unlock();
    }

    static function bin($cmd)
    {
        $dirs = explode(':', getenv("PATH"));
        $dirs[] = "/bin";
        $dirs[] = "/sbin";
        $dirs[] = "/usr/bin";
        $dirs[] = "/usr/sbin";
        $dirs[] = "/usr/share/bin";
        $dirs[] = "/usr/local/bin";
        foreach ($dirs as $dir)
        {
            $cmd = "$dir/$cmd";
            if (file_exists($cmd) && is_executable($cmd))
                return $cmd;
        }
        return false;
    }

    // Primitive context search routine
    // $match is whether string or array of "sets" for OR check,
    // where "set" is whether string or array of strings for AND check, for example:
    // "John Smith" - look for exact name match
    // ("dog", "cat") - look for dogs or cats
    // (("dog", "Fido"), ("cat", "felix"), "zebra") look for text about Fido dogs or Felix cats or zebras
    // Returns array based on $mode:
    // SELECT_HASH - ($key => $value) (default)
    // SELECT_KEYS - array of keys
    // SELECT_VALUES - array of values
    public function select($match, $mode = self::SELECT_HASH, $case_sensitive = true)
    {
        $result = array();
        $this->begin($match, $mode, $case_sensitive = true);
        while (($item = $this->next()) !== false)
        {
            // $key/$value
            if (is_array($item))
                $result[$item[0]] = $item[1];
            else
                $result[] = $item;
        }
        $this->end();
        return $result;
    }

    // Incremental functions, useful when no need for all data at once, like search paging:
    // $r->begin(....);
    // while(($item = $r->next()) !== false)
    // { <do something with $item> }
    // $r->end();
    public function begin($match, $mode, $case_sensitive = true)
    {
        // Just in case
        $this->end();

        // We try to use UNIX builtin fgrep/grep for quick prefilter,
        // so if it exists we use it's output via popen otherwise our direct file descriptor
        $this->select_matches = array();
        $this->select_case_sensitive = $case_sensitive;
        $this->select_mode = $mode;
        $words = array();
        foreach ((is_array($match) ? $match : array($match)) as $i => $set)
        {
            if (is_array($set))
            {
                foreach ($set as $j => $x)
                {
                    $this->select_matches[$i][$j] = $words[] = self::encode($x);
                }
            }
            else
                $this->select_matches[$i] = array($words[] = self::encode($set));
        }

        $fn = $this->filename;
        if (strstr($this->delimiter, "\n"))
        {
            // Trying to use built in UNIX command
            $grep = self::bin("fgrep");
            if (!$grep)
                $grep = self::bin("grep");
        }
        else
            $grep = NULL;

        $this->select_size = $this->seek_end();
        if ($grep)
        {
            $case_flag = $case_sensitive ? "" : "-i";
            $opt_list = '';
            foreach ($words as $word)
                $opt_list .= "-e '$word'";
            $cmd = "cat $fn|$grep $case_flag $opt_list' ";
            $this->select_pipe = popen($cmd, "r");
        }
        else
        {
            $this->seek_set(0);
        }
    }

    public function end()
    {
        // Check if we have last popen active
        if (!empty($this->select_pipe))
        {
            pclose($this->select_pipe);
            $this->select_pipe = null;
        }
        $this->unlock();
    }

    // Returns next found item as pair ($ey, value), $ey, or value based on $match in begin()
    public function next()
    {
        if ($this->select_pipe)
            $fd = $this->select_pipe;
        else
            $fd = $this->fd;

        while (!feof($fd))
        {
            $str = stream_get_line($fd, $this->select_size, $this->delimiter);
            if ($str === false)
                return false;

            $pos = strpos($str, $this->key_delimiter);
            if ($pos === false)
                continue;

            $data_pos = $pos + strlen($this->key_delimiter);
            $ok = false;
            foreach ($this->select_matches as $set)
            {
                $set_ok = true;
                foreach ($set as $word)
                {
                    if (($this->select_case_sensitive ? strpos($str, $word, $data_pos) : stripos($str, $word, $data_pos)) !== false)
                        continue;
                    $set_ok = false;
                    break;
                }
                if ($set_ok === true)
                {
                    $ok = true;
                    break;
                }
            }
            if (!$ok)
                continue;

            switch ($this->select_mode)
            {
                case self::SELECT_KEYS:
                    return self::decode(substr($str, 0, $pos));
                case self::SELECT_VALUES:
                    return self::decode(substr($str, $data_pos));
                default:
                    return array(self::decode(substr($str, 0, $pos)), self::decode(substr($str, $data_pos)));
            }
        }
        return false;
    }

    // Static methods - no objects, just file names
    /**
     * @param $filename
     * @return mixed
     * @throws Exception
     */
    // Cache of objects
    static function object($filename)
    {
        if (!is_array(self::$OBJECTS))
            self::$OBJECTS = array();
        if (isset(self::$OBJECTS[$filename]))
            return self::$OBJECTS[$filename];
        $RDB = new RDB($filename);
        $filename = realpath($filename);
        return self::$OBJECTS[$filename] = $RDB;
    }

    public static function fget($filename, $key)
    {
        $RDB = self::object($filename);
        return $RDB->get($key);
    }

    public static function fput($filename, $key, $data)
    {
        $RDB = self::object($filename);
        return $RDB->put($key, $data);
    }

    public static function fdel($filename, $key)
    {
        $RDB = self::object($filename);
        return $RDB->del($key);
    }

    public static function fselect($filename, $match, $mode = 0, $case_sensitive = true)
    {
        $RDB = self::object($filename);
        return $RDB->select($match, $mode, $case_sensitive);
    }

    public static function fbegin($filename, $match, $mode = 0, $case_sensitive = true)
    {
        $RDB = self::object($filename);
        return $RDB->begin($match, $mode, $case_sensitive);
    }

    public static function fnext($filename)
    {
        $RDB = self::object($filename);
        return $RDB->next();
    }

    public static function fend($filename)
    {
        $RDB = self::object($filename);
        return $RDB->end();
    }

}
