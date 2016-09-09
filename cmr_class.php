<?php

/*
 * CMR Cloud.Mail.Ru API class definition
 * @author Anatoliy Kultenko "tofik"
 * @license BSD http://opensource.org/licenses/BSD-3-Clause
 */

class CMR
{
    private $auth_domain = "https://auth.mail.ru";
    private $cloud_domain = "https://cloud.mail.ru";
    private $cloud_home = "https://cloud.mail.ru/home";
    private $cloud_api_url = "https://cloud.mail.ru/api/v2/";

    private $login;
    private $password;
    private $cookie = "";
    private $token  = "";
    private $shards = [];

    public function __construct( $login, $password) {
        $maxtries = 5;
        $this -> login = $login;
        $this -> password = $password;
        $tries = 0;
        print( "\n# login\n");
        while( !$this -> login( $this -> login, $this -> password) && $tries < $maxtries) {
            $tries++;
            print( "\n# tries = $tries\n");
        }
        if( $tries == 5) return false;
        print( "\n# get_token\n");
        $this -> get_token();
        print( "\n# get_dispatcher\n");
        $this -> get_dispatcher();

        print( "\n# end __construct\n\n");
    }

    private function login( $login, $password) {
        $auth_url = $this -> auth_domain."/cgi-bin/auth";
        $post_data = array( "page" => $this -> cloud_domain, "new_auth_form" => "1","Domain" => "mail.ru", "FailPage" => "",
                            "Login" => $login, "Password" => $password);
        $host = parse_url( $auth_url, PHP_URL_HOST);
        $response = $this -> _curl( $auth_url, false, $post_data);
//        $this -> wbuf2file( "response1.txt", $response); //TODO for test, del in prod //
        if ( $response["code"] != 302) {
            return false;
        }
        $cookie = $response["cook"];
        if( $cookie == "") {
            return false;
        }
        $url = $this -> cloud_domain."/?from=promo&from=authpopup";
        $response = $this -> _curl( $url, $cookie);
//        $this -> wbuf2file( "response2.txt", $response); //TODO for test, del in prod //
        if ( $response["code"] != 302) {
            return false;
        }
        if( preg_match('/Location: (\S*)/', $response['head'], $results)) {
            $url = $results[1];
        }
        $response = $this -> _curl( $url, $cookie);
//        $this -> wbuf2file( "response3.txt", $response); //TODO for test, del in prod //
        if ( $response["code"] != 302) {
            return false;
        }
        if( preg_match('/Location: (\S*)/', $response['head'], $results)) {
            $url = $results[1];
        }
        $response = $this -> _curl( $url, $cookie);
//        $this -> wbuf2file( "response4.txt", $response); //TODO for test, del in prod //
        if ( $response["code"] != 302) {
            return false;
        }
        $cookie = $cookie . "; ". $response["cook"];
        $this -> cookie = $cookie;
        return true;
    }

    private function get_token() {
        if( $this -> token == "") {
            $res_arr = $this -> execute_api_method( "tokens/csrf");
            if( !isset( $res_arr["token"])) {
                return false;
            }
            $this -> token = $res_arr["token"];
        } else return $this -> token;
    }

    private function execute_api_method( $method, array $fields_data = [], $post = false) {
        if( $method != "tokens/csrf") {
            $fields_data[ "token"] = $this -> get_token();
        }
        if( !$post) {
            $url = $this -> cloud_api_url.$method."?".http_build_query( $fields_data);
            $response = $this -> _curl( $url, $this -> cookie);
        } else {
            $response = $this -> _curl( $url, $this -> cookie, $fields_data);
        }
        $this -> wbuf2file( "responses.txt", $response, "a"); //TODO for test, del in prod //
        $res_arr = json_decode( $response["body"], true);
        if( $res_arr["status"] != 200) {
            return false;
        }
        $res_arr = $res_arr["body"];
        return $res_arr;
    }

    public function get_folder( $folder = "/") {
        $params = array( "home" => $folder);
        $response = $this -> execute_api_method( "folder", $params);
        if( !$response) return false;
        return $response;
    }

    public function add_folder( $folder_path) {
        $params = array( "home" => $folder_path, "conflict" => "strict");
        $response = $this -> execute_api_method( "folder/add", $params);
        if( !$response) return false;
        return $response;
    }

    public function get_space() {
        $response = $this -> execute_api_method( "space");
        if( !$response) return false;
        return $response;
    }

    public function get_dispatcher() {
        $response = $this -> execute_api_method( "dispatcher");
        if( !$response) return false;
        foreach( $response as $shard => $item) {
            if( isset( $item[0]["url"])) $this -> shards[ "$shard"] = $item[0]["url"];
        }
        if( count( $this -> shards) > 0) {
            return $this -> shards;
        } else return false;
    }

//  TODO dont work
    public function get_status() {
        $response = $this -> execute_api_method( "status");
        if( !$response) return false;
        return $response;
    }

    public function get_file_info( $file = "/") {
        $params = array( "home" => $file);
        $response = $this -> execute_api_method( "file", $params);
        if( !$response) return false;
        return $response;
    }

    public function get_file( $file) {
        if( $file == "") return false;
        $file_info = $this -> get_file_info( $file);

        if( is_string( $file) && $file_info["type"] == "file") {
            $url = $this -> shards["get"];
            if( $url[strlen($url)-1] == $file[0]) $file = substr( $file, 1);
            $url = $url.rawurlencode( $file);
            if( stripos( $file, '/') !== false) {
                $local_filename = substr( strrchr( $file,'/'),1);
            } else $local_filename = $file;
        }
        if( is_array( $file) || $file_info["type"] == "folder") {
            if( is_array( $file)) {
                $home_list = "[\"".implode("\",\"", $file)."\"]";
                $local_filename = "file_cmr_".time().".zip";
            } else {
                $home_list = "[\"".$file."\"]";
                if( stripos( $file, '/') !== false) {
                    $local_filename = substr( strrchr( $file,'/'),1);
                } else $local_filename = $file;
            }
            print_r( "\nhome_list = $home_list\n");
            $params = array( "home_list" => $home_list, "name" => $local_filename);
            $response = $this -> execute_api_method( "zip", $params);
            if( !$response) return false;
            $url = $this -> cloud_domain.$response;
        }
       
        print_r( "get_url = $url\n");
        print_r( "local_filename = $local_filename\n");

        $fp = @fopen( $local_filename, "wb");
        if( !$fp) { print_r( "cant create file!\n"); return false; }
        $response = $this -> _curl( $url, $this -> cookie, "", $fp);
        fclose( $fp);
        if( !$response) return false;
        print_r( $response);
        return $response;
    }

    public function put_file( $file, $cloud_folder) {
        $file_info = $this -> upload_file( $file);
        $res = $this -> add_file( $file_info, "$cloud_folder/$file");
        if( !$res) return false;
        return $res;
    }

    public function upload_file( $file) {
        if( !isset( $this -> shards["upload"])) return false;
        $url = $this -> shards["upload"];
        $cf = curl_file_create( $file);
        $post_data = array ( "file" => $cf);
        $response = $this -> _curl( $url, $this -> cookie, $post_data);
        $file_info = explode( ';', trim( $response["body"]));
        if( strlen( $file_info[0]) != 40) return fasle;
        return $file_info;
    }

    public function add_file( $file_info, $cloud_folder) {
        $params = array( "home" => $cloud_folder, "hash" => $file_info[0], "size" => $file_info[1], "conflict" => "strict");
        $response = $this -> execute_api_method( "file/add", $params);
        if( !$response) return false;
        return $response;
      }

    public function remove_file( $remote_file_path) {
        $params = array( "home" => $remote_file_path);
        $response = $this -> execute_api_method( "file/remove", $params);
        if( !$response) return false;
        return $response;
      }

    public function move_file( $remote_file_path_old, $remote_folder_new) {
        $params = array( "home" => $remote_file_path_old, "folder" => $remote_folder_new, "conflict" => "strict");
        $response = $this -> execute_api_method( "file/move", $params);
        if( !$response) return false;
        return $response;
      }

    public function copy_file( $remote_file_path_from, $remote_file_path_to) {
        $params = array( "home" => $remote_file_path_from, "folder" => $remote_file_path_to, "conflict" => "strict");
        $response = $this -> execute_api_method( "file/copy", $params);
        if( !$response) return false;
        return $response;
      }

    public function rename_file( $remote_file_path_from, $new_filename) {
        $params = array( "home" => $remote_file_path, "name" => $new_filename, "conflict" => "strict");
        $response = $this -> execute_api_method( "file/rename", $params);
        if( !$response) return false;
        return $response;
      }

    private function _curl( $url, $cookie = false, $post_data = "", $file = false) {
        print( "[curl] url = $url\n");
        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $url);
        curl_setopt( $ch, CURLOPT_REFERER, $url);
        curl_setopt( $ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.17 (KHTML, like Gecko) Chrome/24.0.1312.57 Safari/537.17");
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false);
        if( !$file) curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true);
        if( !$file) curl_setopt( $ch, CURLOPT_HEADER, true);
    //set proxy for work
        curl_setopt( $ch, CURLOPT_PROXY, "proxyf.hq.eximb.com");
        curl_setopt( $ch, CURLOPT_PROXYPORT, 3128);
        curl_setopt( $ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
    //set proxy for work
        if( $cookie) curl_setopt( $ch, CURLOPT_COOKIE, $cookie);
        if( $post_data != "") {
            curl_setopt( $ch, CURLOPT_POST, true);
            curl_setopt( $ch, CURLOPT_POSTFIELDS, $post_data);
        }
        if( $file) {
            curl_setopt( $ch, CURLOPT_FILE, $file);
            curl_setopt( $ch, CURLOPT_BINARYTRANSFER, true);
        }
        $response["text"] = curl_exec( $ch);
        $response["errn"] = curl_errno( $ch);
        $response["info"] = curl_getinfo( $ch);
        $response["code"] = curl_getinfo( $ch, CURLINFO_HTTP_CODE);
        $response["head"] = substr( $response["text"], 0, $response["info"]["header_size"]);
        $response["body"] = substr( $response["text"], $response["info"]["header_size"]);
        unset( $response["text"]);
        preg_match_all("/Set-Cookie: (.*);/U", $response["head"], $res);
        if( isset( $res[1])) $response["cook"] = implode("; ", $res[1]);
        curl_close( $ch);
        return $response;
    }

    //TODO for test, del in prod
    private function wbuf2file( $fn, $buf, $mode = "w") {
        $fp = fopen( $fn, $mode);
        fwrite( $fp, print_r( $buf, true));
        fclose( $fp);
        return;
    }
    //TODO for test, del in prod
}
?>