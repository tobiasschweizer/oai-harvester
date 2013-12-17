<?php
/**
 * Author: Tobias Schweizer, Digital Humanities Lab, University of Basel
 * Contact: t.schweizer@unibas.ch
 *
 * Version: 1.0
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

function print_usage($out) {
    fwrite($out, 'Usage: get_records_with_urls.php -rep e-rara|e-manuscripta|heidelberg [-resume resumptionToken] [-date yyyy-mm-ddThh:mm:ssZ] [-set set] [-sets] [-xml directory] [-v]' . PHP_EOL . PHP_EOL);
    fwrite($out, " -resume\tResume an earlier harvesting process by specifiying a resumption token" . PHP_EOL);
    fwrite($out, " -date\t\tLimit requests by specifying a datestamp in MEZ - 1h: records created or modified >= specified datestamp" . PHP_EOL);
    fwrite($out, " -set\t\tLimit requests by specifying a set" . PHP_EOL);
    fwrite($out, " -sets\t\tTo get a list of the existing sets for a specific provider (no records are being downloaded using this option)" . PHP_EOL);
    fwrite($out, " -xml\t\tSpecifiy a directory to save the xml record-description files (mets) locally" . PHP_EOL);
    fwrite($out, " -v \t\tTo get a list of the imported records to stdout (verbose) " . PHP_EOL . PHP_EOL);

    fwrite($out, 'The script will create a json-File with the chosen provider (e-rara|e-manuscripta|heidelberg) as prefix.' . PHP_EOL);
    fwrite($out, 'Providers: www.e-rara.ch (e-rara), www.e-manuscripta.ch (e-manuscripta) and digi.ub.uni-heidelberg.de (heidelberg)' . PHP_EOL);
}

if ($argc < 2) {
    print_usage(STDERR);
    exit(1);
}

// these params are optional, init them with default values
$set = '';
$datestr = '';
$listsets = FALSE;
$verbose = FALSE;
$store_xml = FALSE;
$resume = FALSE;

for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] == '-rep') {
        $i++;

        if (!isset($argv[$i])) {
            print_usage(STDERR);
            exit(1);
        }

        if ($argv[$i] == 'e-rara') {
            $provider = 'e-rara';
            $base_url = 'http://www.e-rara.ch';
            $oai_frag = '/oai';
        } elseif ($argv[$i] == 'e-manuscripta') {
            $provider = 'e-manuscripta';
            $base_url = 'http://www.e-manuscripta.ch';
            $oai_frag = '/oai/';
        } elseif ($argv[$i] == 'heidelberg') {
            $provider = 'heidelberg';
            $base_url = 'http://digi.ub.uni-heidelberg.de';
            $oai_frag = '/cgi-bin/digioai.cgi/';
        } else {
            print_usage(STDERR);
            exit(1);
        }
    }
    if ($argv[$i] == '-resume') {
	$i++;
	    
	if (!isset($argv[$i])) {
	    print_usage(STDERR);
	    exit(1);
	}

	$resume = $argv[$i];
	
    }
    if ($argv[$i] == '-date') {
        $i++;

        if (!isset($argv[$i])) {
            print_usage(STDERR);
            exit(1);
        }

        // limit request by datestamp
        $datestamp = $argv[$i];

        // check $datestamp
        if (preg_match('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/', $datestamp) !== 1) {
            fwrite(STDERR, 'Datestamp format is invalid' . PHP_EOL);
            exit(1);
        }
        $datestr = '&from=' . $datestamp;

    }
    if ($argv[$i] == '-xml') {
        $i++;

        if (!isset($argv[$i])) {
            print_usage(STDERR);
            exit(1);
        }

        // make sure there is an ending slash
        if (!(substr($argv[$i], -1) === '/'))  {
            $argv[$i] .= '/';
        }

        // check if given dir exists
        if (is_dir($argv[$i])) {

            if (!is_writable($argv[$i])) {
                fwrite(STDERR, 'No write permissions for directory ' . $argv[$i] . PHP_EOL);
                exit(1);
            }



            $store_xml = $argv[$i];
        } else {
            // create directory
            if(!mkdir($argv[$i])) {
                fwrite(STDERR, 'No permissions to create directory ' . $argv[$i] . PHP_EOL);
                exit(1);
            }

            $store_xml = $argv[$i];
        }
    }
    if ($argv[$i] == '-set') {
        $i++;

        if (!isset($argv[$i])) {
            print_usage(STDERR);
            exit(1);
        }

        $set = '&set=' . $argv[$i];
    }
    if ($argv[$i] == '-sets') {

        $listsets = TRUE;
    }
    if ($argv[$i] == '-v') {

        $verbose = TRUE;
    }

}


unset($i);

if (!isset($provider)) {
    fwrite(STDERR, 'Option -rep not set' . PHP_EOL);

    print_usage(STDERR);
    exit(1);
}

if ($listsets) {
    // provide the sets for the given provider
    $sets = file_get_contents($base_url. $oai_frag . '?verb=ListSets');
    if ($sets === FALSE) {
        // no response
        fwrite(STDERR, $base_url. $oai_frag . '?verb=ListSets' . PHP_EOL);
        fwrite(STDERR, 'Could not be retrieved' . PHP_EOL);
        fwrite(STDERR, $http_response_header[0] . PHP_EOL);
        exit(1);
    }

    $xml = new DOMDocument();
    $xml->loadXML($sets);
    $token = $xml->getElementsByTagName('resumptionToken');

    $sets = $xml->getElementsByTagName('set');

    if ($sets->length == 0) {
        echo 'No sets for the given provider' . PHP_EOL;
        exit(0);
    }

    echo 'The following sets exist (please specify the name without double quotes for the -set option):' . PHP_EOL . PHP_EOL;

    do {

        foreach ($sets as $set) {
            // inside a record
            if ($set->nodeName == 3) continue; // text node

            foreach ($set->childNodes as $set_path) {
                // inside a record
                if ($set_path->nodeName == 3) continue; // text node

                if ($set_path->nodeName == 'setSpec') echo "\t\"" . $set_path->textContent . '", ';
                if ($set_path->nodeName == 'setName') echo $set_path->textContent . PHP_EOL;
            }

            echo PHP_EOL;

        }

        if ($token->length == 0) break;

        $sets = file_get_contents($base_url. $oai_frag . '?verb=ListSets&resumptionToken=' . $token->item(0)->textContent);
        if ($sets === FALSE) {
            // no response
            fwrite(STDERR, $base_url. $oai_frag . '?verb=ListSets' . PHP_EOL);
            fwrite(STDERR, 'Could not be retrieved' . PHP_EOL);
            fwrite(STDERR, $http_response_header[0] . PHP_EOL);
            exit(1);
        }

        $xml = new DOMDocument();
        $xml->loadXML($sets);
        $token = $xml->getElementsByTagName('resumptionToken');

        $sets = $xml->getElementsByTagName('set');

    } while (true);
    unset($token);
    exit(0);
}

if ($resume === FALSE) {
    // new harvesting process
    $conts = @file_get_contents($base_url. $oai_frag . '?verb=ListRecords&metadataPrefix=oai_dc' . $datestr . $set);
    $cur_token = 'initial'; // no token yet, it is the first request
} else {
    // resume an aborted harvesting process
    $conts = @file_get_contents($base_url. $oai_frag . '?verb=ListRecords&resumptionToken=' . $resume);
    $cur_token = $resume; // store token of current request
}

if ($conts === FALSE) {
    // no response
    fwrite(STDERR, 'Initial Get request failed' . PHP_EOL);
    fwrite(STDERR, $http_response_header[0] . PHP_EOL);
    exit(1);
}

$xml = new DOMDocument();
$xml->loadXML($conts);

// check for errors
$error = $xml->getElementsByTagName('error');
if ($error->length > 0) {
    // an error occurred
    fwrite(STDERR, $error->item(0)->textContent . PHP_EOL);
    exit(1);
}

$date = $xml->getElementsByTagName('responseDate');
$date = $date->item(0)->textContent;

$token = $xml->getElementsByTagName('resumptionToken');

if (file_exists($provider . '_records.json')) {
    fwrite(STDERR, 'Existing file ' . $provider . '_records.json would be overwritten, please move it to another destination before runing this application' . PHP_EOL);
    exit(1);
}

$file_ptr = fopen($provider . '_records.json', 'w');
fwrite($file_ptr, '{"datestamp":"'. $date  .'"');

echo 'Starting Harvesting ' .PHP_EOL;
echo $date . PHP_EOL . PHP_EOL;

$counter = 1;
$overall_rec_counter = 1;

do {
    
    $rec_counter = 1;

    $records = $xml->getElementsByTagName('record');
    foreach ($records as $record) {
        // foreach record node

        $record_array = array();
        $record_array['header'] =  array();
        $record_array['metadata'] =  array();

        fwrite($file_ptr, ',');

        foreach ($record->childNodes as $rec_node) {
            // inside a record
            if ($rec_node->nodeType == 3) continue; // text node

            if ($rec_node->nodeName == 'header') {
                // header

                foreach ($rec_node->childNodes as $header_node) {
                    if ($header_node->nodeType == 3) continue; // text node

                    if ($header_node->nodeName == 'identifier') {
                        $id = $header_node->nodeValue;
                    }

                    if (array_key_exists($header_node->nodeName, $record_array['header'])) {
                        if (!is_array($record_array['header'][$header_node->nodeName])) {
                            $tmp = $record_array['header'][$header_node->nodeName];
                            $record_array['header'][$header_node->nodeName] = array();
                            $record_array['header'][$header_node->nodeName][] = $tmp;
                        }
                        $record_array['header'][$header_node->nodeName][] = $header_node->textContent;
                    } else {
                        $record_array['header'][$header_node->nodeName] = $header_node->textContent;
                    }
                }

            } else {
                // metadata
                foreach ($rec_node->childNodes as $metadata_node) {
                    if ($metadata_node->nodeType == 3) continue; // text node
                    foreach ($metadata_node->childNodes as $metadata_node_item) {
                        if ($metadata_node_item->nodeType == 3) continue; // text node

                        if (array_key_exists($metadata_node_item->nodeName, $record_array['metadata'])) {
                            if (!is_array($record_array['metadata'][$metadata_node_item->nodeName])) {
                                $tmp = $record_array['metadata'][$metadata_node_item->nodeName];
                                $record_array['metadata'][$metadata_node_item->nodeName] = array();
                                $record_array['metadata'][$metadata_node_item->nodeName][] = $tmp;
                            }
                            $record_array['metadata'][$metadata_node_item->nodeName][] = $metadata_node_item->textContent;
                        } else {
                            $record_array['metadata'][$metadata_node_item->nodeName] = $metadata_node_item->textContent;
                        }
                    }
                }
            }

        }


        // get file ids by using the mets metadata prefix
	$loop = FALSE;
	$loop_cnt = 0;
	do {
	    $mets_conts = @file_get_contents($base_url . $oai_frag . '?verb=GetRecord&metadataPrefix=mets&identifier=' . $id);
	    if ($mets_conts === FALSE) {
		++$loop_cnt;
		$loop = TRUE;
		sleep(30);
	    } else {
		$loop = FALSE;
	    }
	    if ($loop === TRUE && $loop_cnt > 3) {
		// no response
		fwrite(STDERR, $base_url . $oai_frag . '?verb=GetRecord&metadataPrefix=mets&identifier=' . $id . PHP_EOL);
		fwrite(STDERR, 'Could not be retrieved' . PHP_EOL);
		fwrite(STDERR, $http_response_header[0] . PHP_EOL);
		fwrite(STDERR, 'Resume harvesting using this token: ' . $cur_token . PHP_EOL);
		break 2;
	    }
	} while ($loop);
	unset($loop, $loop_cnt);

        if ($store_xml !== FALSE) {
            // write mets to local filesystem

            $tmp_ptr = fopen($store_xml . $id . '.xml', 'w');
            fwrite($tmp_ptr, $mets_conts);
            fclose($tmp_ptr);
            unset($tmp_ptr);
        }

        sleep(2);

        if ($provider == 'e-rara' || $provider == 'e-manuscripta') {

            $mets_xml = new DOMDocument();
            $mets_xml->loadXML($mets_conts);

            $ns = $mets_xml->lookupNamespaceURI('mets');
            $fileSec = $mets_xml->getElementsByTagNameNS($ns, 'fileSec');

            $record_array['urls'] = array();
            $record_array['urls']['max'] = array();
            $record_array['urls']['thumb'] = array();

            foreach ($fileSec->item(0)->childNodes as $fileGrp) {
                if ($fileGrp->nodeType == 3) continue; // text node

                if ($fileGrp->getAttribute('USE') == 'MAX') {


                    foreach($fileGrp->childNodes as $file) {
                        if ($file->nodeType == 3) continue; // text node

                        $img_id = $file->getAttribute('ID');
                        $pos = strrpos($img_id, '_');

                        $img_id = substr($file->getAttribute('ID'), ($pos+1));
                        $record_array['urls']['max'][] = $base_url . '/image/view/' . $img_id;
                        $record_array['urls']['thumb'][] = $base_url . '/image/thumb/' . $img_id;

                    }

                    break;
                }

            }

        } elseif ($provider == 'heidelberg') {

            $record_array['urls'] = array();
            $record_array['urls']['max'] = array();
            $record_array['urls']['thumb'] = array();

            $mets_xml = new SimpleXMLElement($mets_conts);
            $ns = $mets_xml->getDocNamespaces(TRUE);

            $mets_xml->registerXPathNamespace('mets', $ns['mets']);
            $mets_xml->registerXPathNamespace('xlink', $ns['xlink']);
            $full_imgs = $mets_xml->xpath('//mets:fileGrp[@USE="MAX"]/mets:file/mets:FLocat');
            $thumbs_imgs = $mets_xml->xpath('//mets:fileGrp[@USE="THUMBS"]/mets:file/mets:FLocat');

            foreach ($full_imgs as $full_img) {
                foreach ($full_img->attributes($ns['xlink']) as $url) {
                    $record_array['urls']['max'][] = $url->__toString();
                }
            }

            foreach ($thumbs_imgs as $thumb_img) {
                foreach ($thumb_img->attributes($ns['xlink']) as $url) {
                    $record_array['urls']['thumb'][] = $url->__toString();
                }
            }

        }

        if (count($record_array['urls']['max']) == 0 || count($record_array['urls']['thumb']) == 0) fwrite(STDERR, 'No images given for ' . $id . PHP_EOL);
        if (count($record_array['urls']['max']) != count($record_array['urls']['thumb'])) fwrite(STDERR, 'Number of max images not equals number of thumbs for ' . $id . PHP_EOL);

        echo $cur_token . ', Request num: ' . $counter .  ', record: ' . $rec_counter++ . ' (' . $overall_rec_counter++ . '), id= '. $id . PHP_EOL;

        if ($verbose) {
            print_r($record_array);
            echo '---------------------------' . PHP_EOL;
            echo '---------------------------' . PHP_EOL;
        }

        // write part of json
        fwrite($file_ptr, '"' . $id . '"' . ':' . json_encode($record_array));

        unset($id);
        unset($record_array);

    }

    // setup new xml
    if ($token->length == 0) break; // no token anymore, leave loop

    $loop = FALSE;
    $loop_cnt = 0;
    do {
	$conts = @file_get_contents($base_url . $oai_frag . '?verb=ListRecords&resumptionToken=' . $token->item(0)->textContent);
	
	if ($conts === FALSE) {
	    ++$loop_cnt;
	    $loop = TRUE;
	    sleep(30);
	} else {
	    $loop = FALSE;
	}
	if ($loop === TRUE && $loop_cnt > 3) {
	    // no response
	    fwrite(STDERR, $base_url . $oai_frag . '?verb=ListRecords&resumptionToken=' . $token->item(0)->textContent . PHP_EOL);
	    fwrite(STDERR, 'Could not be retrieved' . PHP_EOL);
	    fwrite(STDERR, $http_response_header[0] . PHP_EOL);
	    fwrite(STDERR, 'Resume harvesting with this token: ' . $token->item(0)->textContent . PHP_EOL);
	    break;
	}
    } while ($loop);
    unset($loop, $loop_cnt);
    sleep(1);

    $xml = new DOMDocument();
    $xml->loadXML($conts);

    $cur_token = $token->item(0)->textContent; // $token is the identifier for the request which will be processed in the coming loop
    $token = $xml->getElementsByTagName('resumptionToken');

    $counter++;

} while (true);

fwrite($file_ptr, '}');
fclose($file_ptr);

exit(0);

?>