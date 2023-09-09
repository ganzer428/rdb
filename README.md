# Rdb
Simple key/value database using plain text file where records are sorted 
by key and search performed using classic dichotomic algorithm:

https://en.wikipedia.org/wiki/Dichotomic_search 

Could be used for some simple apps that could work straight away 
without configuration of database etc. Other usage is a quick context
search in huge inventory - on standard UNIX server it engages
builtin `fgrep` which is quite effective.

-------------------------------------------------------------------
Standard usage:

    include_once("RDB.php");
    
    $r = new Rdb($filename);
    $value1 = $r->get($key1);
    $r->put($key2, $value2);

    // static methods
    $value1 = RDBS::get($filename, $key1);
    RDBS::put($filename, $key2, $value2);

-------------------------------------------------------------------
Records are kept as:

`<KEY><KEY_DELIMITER><DATA><RECORD_DELIMITER>`

where:
    
    KEY, DATA - raw urlencoded key and data to store
    KEY_DELIMITER - by default "\t"
    RECORD_DELIMITER - by default "\n"
 
Encoding functions and delimiters can be changed in the code
to something else, just keep in mind that delimiters shouldn't appear in
key or data after encoding. For example if key and data contain no 
tabulations or newlines then there's no need for encoding at all 
and it will look like an ordinary text file.
-------------------------------------------------------------------


