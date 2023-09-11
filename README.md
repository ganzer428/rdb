# RDB
Simple key/value database using plain text file where records are sorted 
by key and search is performed using classic dichotomic algorithm:

https://en.wikipedia.org/wiki/Dichotomic_search 

Could be used for some simple apps that could work straight away 
without running database etc., I personally use it for quick access
to my audio collection using a cache of data. Other usage is a quick context
search - on standard UNIX server it engages builtin `fgrep` 
which is quite effective. 

-------------------------------------------------------------------
Usage:

    include_once("RDB.php");
    
    $r = new RDB($filename);
    
    // Basic operations
    $value1 = $r->get($key1);
    $r->put($key2, $value2);
    $r->del($key3);
    
    // Context search
    foreach($r->select($text_match) as $key=>$value)
    {
    ...
    }
    
    $keys = $r->select($text_match, 1);
    foreach(array_slice($key, $page_start, $page_len) as $key)
    {
        $value = $r->get($key);
        ...
    }
    
    // No case sensitive - 3rd argument is false
    $values = $r->select($text_match, 2, false); 
    
    // Low level implementation
    $r->begin($text_match, 1);
    $keys = array();
    while(($key = $r->next()) !== false)
    {
        $keys[] = $key;
        ...
    }
    $r->end();


Static methods - directly to the file

    $value1 = RDB::fget($filename, $key1);
    RDB::fput($filename, $key2, $value2);
    RDB::fdel($filename, $key3, $value2);
    $data = RDB::fselect($filename, $text_match);    
    $keys = RDB::fselect($filename, $text_match, 1);    
    
-------------------------------------------------------------------
Records are kept as:

`<KEY><KEY_DELIMITER><DATA><RECORD_DELIMITER>`

where:
    
    KEY, DATA - raw urlencoded key and data to store
    KEY_DELIMITER - by default "\t"
    RECORD_DELIMITER - by default "\n"
 
Encoding functions and delimiters can be changed in the code
to something else, just keep in mind that delimiters shouldn't appear in
key or data after encoding. For example if key and data contain neither 
tabulations nor newlines then there's no need for encoding at all 
and data looks like an ordinary text file:

    USER1   John Smith
    USER2   Hank Williams

-------------------------------------------------------------------

