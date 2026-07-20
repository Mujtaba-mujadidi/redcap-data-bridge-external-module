<?php

namespace OxfordVaccineGroup\RedcapDataBridge;

use ExternalModules\ExternalModules;
use REDCap;

class RedcapDataBridge extends \ExternalModules\AbstractExternalModule
{

    // function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)
    // {
    //     $this->pushDataToParentServer($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance);
    // }

    function redcap_survey_acknowledgement_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)
    {
        $this->pushDataToParentServer($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance);
    }

    private function pushData($url, $token, $dataJson)
    {
        $data = array(
            'token' => $token,
            'content' => 'record',
            'action' => 'import',
            'format' => 'json',
            'type' => 'flat',
            'overwriteBehavior' => 'normal',
            'forceAutoNumber' => 'true',
            'data' => $dataJson,
            'returnContent' => 'ids',
            'returnFormat' => 'json'
        );
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_VERBOSE, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
        $output = curl_exec($ch);
        return $output;
        curl_close($ch);
    }

    /**
     * 
     */
    private function getRbdMap($instrument)
    {
        //Get the data dictionary for the current project
        $ddJson = REDCap::getDataDictionary(
            PROJECT_ID,               // $project_id (null uses current project)
            'json',            // $returnFormat ('csv', 'json', or 'array')
            true,               // $exportCsvHeadersAsLabels
            null,               // $fields (we don't need to limit fields manually here)
            [$instrument], // $instruments (limit to this instrument)
            false               // $draftMode
        );

        //Parse the ddJson as json
        $ddJson = json_decode($ddJson);

        foreach ($ddJson as $fieldObject) {
            $fieldAnnotation = $fieldObject->field_annotation;
            //If field annotation is not empty and it contains the word @RDB-MAP then return the field object
            if (!empty($fieldAnnotation) && stripos($fieldAnnotation, "@RDB-MAP") !== false) {
                //extract the @RDB-MAP tag contents from the field annotation
                // Recursive, quote-aware pattern that matches @RDB-MAP( ...balanced... )
                $pattern = '/@RDB-MAP\s*\(\s*(?<inner>(?:[^()"\'()]+' .
                    '|"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|\((?&inner)\))*)\s*\)/si';

                $foundMaps = [];
                if (preg_match_all($pattern, $fieldAnnotation, $m, PREG_SET_ORDER)) {
                    foreach ($m as $map) {
                        $actionTagAttributes = $map['inner'];
                        preg_match_all('/(condition|map)="([^"]+)"/', $actionTagAttributes, $matches, PREG_SET_ORDER);
                        foreach ($matches as $match) {
                            $key = strtolower($match[1]);
                            $value = trim($match[2]);

                            if ($key === 'condition') {
                                $result['condition'] = $value;
                            } elseif ($key === 'map') {
                                // Parse map string into associative array
                                $pairs = array_map('trim', explode(',', $value));
                                foreach ($pairs as $pair) {
                                    if (strpos($pair, '=>') !== false) {
                                        list($from, $to) = array_map('trim', explode('=>', $pair));
                                        $result['map'][$from] = $to;
                                    }
                                }
                            }
                        }
                    }
                    $foundMaps[] = $result;
                }
                return $foundMaps;
            }
        }
    }

    function pushDataToParentServer($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance)
    {
        $rbdMap = $this->getRbdMap($instrument);
        if ($rbdMap) {
            //Get the PMD project URL and API token from the project settings
            $pmdProjectUrl = $this->getProjectSetting('api_url');
            $pmdApiToken = $this->getProjectSetting('api_key');

            //Retuen if either PMD project URL or API token is not set
            if (empty($pmdProjectUrl) || empty($pmdApiToken)) {
                $this->printToConsole("PMD Project URL or API token is not set. Data transmission aborted.");
                return;
            }

            //Get the record ID field
            $recordFieldName = REDCap::getRecordIdField($project_id);
            //Loop through each map, check if the condition is met, and if so, get and map the data and push it to the parent server
            foreach ($rbdMap as $map) {
                $condition = $map["condition"];
                $fieldsMap = $map["map"];
                $conditionMet = true;
                //If condition is not empty then evaluate it
                if (!empty($condition)) {
                    //Evaluate the condition
                    $conditionMet = REDCap::evaluateLogic($condition, $project_id, $record, $event_id, $repeat_instance);
                }

                if ($conditionMet) {
                    //Extract the fields from the map keys
                    $fieldsToExtract = array_keys($fieldsMap);
                    //Add recordFieldName to the fields if it does not exisits 
                    if (!in_array($recordFieldName, $fieldsToExtract)) {
                        $fieldsToExtract[] = $recordFieldName;
                    }



                    //Get the data for the specified fields from redcap
                    $params = array('return_format' => 'json', 'records' => array($record), 'fields' => $fieldsToExtract, 'exportSurveyFields' => true);
                    $data = REDCap::getData($params);
                    $data = json_decode($data, true);
                    //Map the data according to the fields map for import
                    //Push the record id first
                    foreach ($data as $recordData) {
                        //Loop through each field of the recordData and if it exists in the fieldsMap then map it to the respective field
                        foreach ($recordData as $fieldName => $value) {
                            $fromField = (strpos($fieldName, "___") !== false) ? explode("___", $fieldName)[0] : $fieldName;

                            //Check if fieldMap has the fieldName
                            if (isset($fieldsMap[$fromField])) {
                                $toField = $fieldsMap[$fromField];
                                //If fieldName is a checkbox then append the code to the toField
                                if (strpos($fieldName, "___") !== false) {
                                    $code = explode("___", $fieldName)[1];
                                    $toField = $toField . "___" . $code;
                                }
                                $mappedData[$toField] = $value;
                            }
                        }
                        //Push the record id if it is not already pushed
                        if (!isset($mappedData[$recordFieldName])) {
                            $mappedData[$recordFieldName] = $recordData[$recordFieldName];
                        }
                    }

                    //Print both from data and to data
                    print_r("From Data: ");
                    print_r($data);
                    print_r("<br><br><br><br>To Data: ");
                    print_r($mappedData);
                    print_r("<br><br><br><br>");

                    //Push the mapped data to the parent server
                    $mappedDataJson = json_encode(array($mappedData));
                    $output = $this->pushData($pmdProjectUrl, $pmdApiToken, $mappedDataJson);
                    //check if output contains error
                    if (strpos($output, 'error') !== false) {
                        $toEmail = $this->getProjectSetting('dm_email');
                        $subject = "Error pushing data to PMD server";
                        $message = "An error occurred while pushing data to the PMD server. Error details: " . $output;
                        $headers = "From: " . $toEmail;

                        print_r("Error pushing data to PMD server: " . $output . "<br>");
                        //CCheck if email was sent successfully
                        if (mail($toEmail, $subject, $message, $headers)) {
                            print_r("Error email sent successfully.");
                        } else {
                            print_r("Error sending error email.");
                        }
                    } else {
                        print_r("Data pushed successfully to PMD server: " . $output);
                    }
                }
            }
        } else {
            print_r("No RBD Map found for instrument: " . $instrument);
        }
    }


    function printToConsole($data)
    {
        echo "<script>console.log(" . json_encode($data) . ");</script>";
    }
}
