<?php

const VERBOSE = 0;

class Rdb
{
    protected $fd;
    public $file_name;
    public $delimiter;
    public $key_delimiter;
    public $chmod;
    const MAX_KEY_LENGTH = 1024;

    /**
     * Rdb constructor.
     * @param $file_name
     * @param int $chmod
     * @param string $delimiter
     * @param string $key_delimiter
     * @throws Exception
     */
    function __construct($file_name, $chmod = 0644, $delimiter = "\n", $key_delimiter = "\t")
    {
        $this->fd = fopen($file_name, file_exists($file_name) ? "r+" : "w+");
        if ($this->fd === FALSE)
            throw new Exception("Can't open file $file_name");
        $this->chmod = $chmod;
        $this->file_name = $file_name;
        $this->delimiter = $delimiter;
        $this->key_delimiter = $key_delimiter;
        $this->chmod();
    }

    public function chmod()
    {
        chmod($this->file_name, $this->chmod);
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
    // Returns such position or false if there chunk is one record
    function middle($start_pos, $end_pos)
    {
        if(VERBOSE)
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
        if($key === false)
            return false;
        $end_pos = $this->seek_end();
        $pos = $this->seek_set($pos + strlen($key) + strlen($this->key_delimiter));
        $data = stream_get_line($this->fd, $end_pos - $pos, $this->delimiter);
        if($data === false)
            return false;
        return array($key, $data);
    }

    function read_data($pos)
    {
        $pair = $this->read_pair($pos);
        if($pair === false)
            return false;
        return $pair[1];
    }

    function strcmp($a, $b)
    {
        $x  = strcmp($a, $b);
        if($x < 0)
            return -1;
        if($x > 0)
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
            if(VERBOSE)
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
                if($end_key === false)
                    return array(false, $end_pos);

                return array(false, $start_pos);
            }

            $middle_key = $this->read_key($middle_pos);
            switch($x = $this->strcmp($key, $middle_key))
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

    public function get($key)
    {
        $ekey = rawurlencode($key);
        $this->lock();
        list($ok, $pos) = $this->seek($ekey);
        if($ok)
            $data = rawurldecode($this->read_data($pos));
        else
            $data = false;
        $this->unlock();
        return $data;
    }

    public function put($key, $data)
    {
        if (!$key)
            return false;
        $ekey = rawurlencode($key);
        $edata = rawurlencode($data);
        $this->lock(true);
        list($ok, $pos) = $arr = $this->seek($ekey);
        if($ok === true)
        {
            $pair = $this->read_pair($pos);
            if($pair === false)
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
        $this->chmod();
    }

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
    function subst($pos, $old_len, $data, $new_len = false)
    {
        if(!$new_len)
            $new_len = strlen($data);
        $end = $this->seek_end();

        $rest = $end - $pos - $old_len;
        if ($rest < 0)
            throw new Exception("ILLEGAL SUBST: $pos/$old_len <= $new_len ($data)");

        if ($old_len != $new_len)
        {
            $this->shift($pos + $old_len, $pos + $new_len, $rest);
            if ($new_len < $old_len)
                ftruncate($this->fd, $end + $new_len - $old_len);
        }

        if ($new_len > 0)
        {
            $this->seek_set($pos);
            $this->write($data, $new_len);
        }
        return;
    }

}
