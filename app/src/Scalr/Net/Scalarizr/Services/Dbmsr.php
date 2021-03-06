<?php

abstract class Scalr_Net_Scalarizr_Services_Dbmsr extends Scalr_Net_Scalarizr_Client
{
    public function growStorage($volumeConfig, $newSize, $platformAccessData)
    {
        $params = new stdClass();
        $params->volume = $volumeConfig;

        if ($volumeConfig->type == 'ebs') {
            $params->growth = new stdClass();
            $params->growth->size = $newSize;
        } elseif ($volumeConfig->type == 'raid') {
            $params->growth = new stdClass();
            $params->growth->disks = new stdClass();
            $params->growth->disks->size = $newSize;
        }
        $params->_platform_access_data = $platformAccessData;

        $params->async = true;

        return $this->request("grow_volume", $params)->result;
    }

    public function replicationStatus()
    {
        return $this->request("replication_status")->result;
    }
}