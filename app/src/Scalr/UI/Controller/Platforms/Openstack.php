<?php

use \Scalr\Service\OpenStack\Services\Servers\Type\ServersExtension;

class Scalr_UI_Controller_Platforms_Openstack extends Scalr_UI_Controller
{
    public function xGetOpenstackResourcesAction()
    {
        $client = $this->environment->openstack($this->getParam('platform'), $this->getParam('cloudLocation'));
        $data = array();

        // List flavors
        $data['flavors'] = array();
        foreach ($client->servers->listFlavors() as $flavor) {
            $data['flavors'][] = array(
                'id' => (string)$flavor->id,
                'name' => $flavor->name
            );
        }

        try {
            if ($client->hasService('volume'))
            {
                $data['volume_types'] = array();
                $volumeTypes = $client->volume->listVolumeTypes()->toArray();
                foreach ($volumeTypes as $volumeType) {
                    $data['volume_types'][] = array(
                        'id' => $volumeType->id,
                        'name' => $volumeType->name
                    );
                }

                //TODO: Add support for extra-specs
            }
        } catch (Exception $e) {}

        if ($client->hasService('network')) {
            $data['ipPools'] = array(array('id' =>'', 'name' => ''));
            $data['networks'] = array();
            $networks = $client->network->listNetworks();

            $tenantId = $client->getConfig()->getAuthToken()->getTenantId();

            foreach ($networks as $network) {
                if ($network->status == 'ACTIVE') {
                    if ($network->{"router:external"} == true || $network->name == 'public') {
                        $data['ipPools'][] = array(
                            'id' => $network->id,
                            'name' => $network->name
                        );
                    } else {
                        if ($tenantId == $network->tenant_id) {
                            $data['networks'][] = array(
                                'id' => $network->id,
                                'name' => $network->name
                            );
                        }
                    }
                }
            }
        } else {
            //Check floating IPs
            if ($client->servers->isExtensionSupported(ServersExtension::EXT_FLOATING_IP_POOLS))
            {
                $data['ipPools'] = array(array('id' =>'', 'name' => ''));
                $pools = $client->servers->listFloatingIpPools();
                foreach ($pools as $pool) {
                    $data['ipPools'][] = array(
                        'id' => $pool->name,
                        'name' => $pool->name
                    );
                }
            }
        }


        $this->response->data(array('data' => $data));
    }

    public function xGetNetworkResourcesAction() {

        $client = $this->environment->openstack($this->getParam('platform'), $this->getParam('cloudLocation'));

        if ($client->hasService('network')) {
            $data['ipPools'] = array(array('id' =>'', 'name' => ''));
            $data['networks'] = array();
            $networks = $client->network->listNetworks();

            $tenantId = $client->getConfig()->getAuthToken()->getTenantId();

            foreach ($networks as $network) {
                if ($network->status == 'ACTIVE') {
                    if ($network->{"router:external"} == true || $network->name == 'public') {
                        $data['ipPools'][] = array(
                            'id' => $network->id,
                            'name' => $network->name
                        );
                    } else {
                        if ($tenantId == $network->tenant_id) {
                            $data['networks'][] = array(
                                'id' => $network->id,
                                'name' => $network->name
                            );
                        }
                    }
                }
            }
        } else {
            //Check floating IPs
            if ($client->servers->isExtensionSupported(ServersExtension::EXT_FLOATING_IP_POOLS)) {

                $data['floatingIps'] = $this->getFloatingIpsList(
                        $this->getParam('cloudLocation'),
                        $this->getParam('farmRoleId'),
                        $client
                );

                $data['ipPools'] = array(array('id' =>'', 'name' => ''));
                $pools = $client->servers->listFloatingIpPools();
                foreach ($pools as $pool) {
                    $data['ipPools'][] = array(
                        'id' => $pool->name,
                        'name' => $pool->name
                    );
                }
            }
        }

        $this->response->data(array('data' => $data));
    }

    private function getFloatingIpsList($cloudLocation, $farmRoleId, \Scalr\Service\OpenStack\OpenStack $client) {
        if ($farmRoleId) {
            $dbFarmRole = DBFarmRole::LoadByID($farmRoleId);
            $this->user->getPermissions()->validate($dbFarmRole);

            $maxInstances = $dbFarmRole->GetSetting(DBFarmRole::SETTING_SCALING_MAX_INSTANCES);
            for ($i = 1; $i <= $maxInstances; $i++) {
                $map[] = array('serverIndex' => $i);
            }

            $servers = $dbFarmRole->GetServersByFilter();
            for ($i = 0; $i < count($servers); $i++) {
                if ($servers[$i]->status != SERVER_STATUS::TERMINATED && $servers[$i]->status != SERVER_STATUS::TROUBLESHOOTING && $servers[$i]->index) {
                    $map[$servers[$i]->index - 1]['serverIndex'] = $servers[$i]->index;
                    $map[$servers[$i]->index - 1]['serverId'] = $servers[$i]->serverId;
                    $map[$servers[$i]->index - 1]['remoteIp'] = $servers[$i]->remoteIp;
                    $map[$servers[$i]->index - 1]['instanceId'] = $servers[$i]->GetProperty(EC2_SERVER_PROPERTIES::INSTANCE_ID);
                }
            }

            $ips = $this->db->GetAll('SELECT ipaddress, instance_index FROM elastic_ips WHERE farm_roleid = ?', array($dbFarmRole->ID));
            for ($i = 0; $i < count($ips); $i++) {
                $map[$ips[$i]['instance_index'] - 1]['elasticIp'] = $ips[$i]['ipaddress'];
            }
        }

        $list = $client->servers->floatingIps->list();

        $ips = array();
        foreach ($list as $ip) {
            $itm = array(
                'ipAddress'  => $ip->ip,
                'instanceId' => $ip->instance_id,
            );

            $info = $this->db->GetRow("
                SELECT * FROM elastic_ips WHERE ipaddress = ? LIMIT 1
            ", array($itm['ipAddress']));

            if ($info) {
                try {
                    if ($info['server_id'] && $itm['instanceId']) {
                        $dbServer = DBServer::LoadByID($info['server_id']);
                        if ($dbServer->GetProperty(OPENSTACK_SERVER_PROPERTIES::SERVER_ID) != $itm['instanceId']) {
                            for ($i = 0; $i < count($map); $i++) {
                                if ($map[$i]['elasticIp'] == $itm['ipAddress'])
                                    $map[$i]['warningInstanceIdDoesntMatch'] = true;
                            }
                        }
                    }

                    $farmRole = DBFarmRole::LoadByID($info['farm_roleid']);
                    $this->user->getPermissions()->validate($farmRole);

                    $itm['roleName'] = $farmRole->GetRoleObject()->name;
                    $itm['farmName'] = $farmRole->GetFarmObject()->Name;
                    $itm['serverIndex'] = $info['instance_index'];
                } catch (Exception $e) {}
            }

            //TODO: Mark Router EIP ad USED

            $ips[] = $itm;
        }

        return array('map' => $map, 'ips' => $ips);
    }

    public function xGetNetworksAction()
    {
        if (!$this->getParam('cloudLocation')) {
            $cloudLocations = array_keys(self::loadController('Platforms')->getCloudLocations($this->getParam('platform'), false));
        } else {
            $cloudLocations = array($this->getParam('cloudLocation'));
        }

        $data = array();

        foreach ($cloudLocations as $cloudLocation) {
            $client = $this->environment->openstack($this->getParam('platform'), $cloudLocation);
            if ($client->hasService('network')) {
                $networks = $client->network->listNetworks();
                foreach ($networks as $network) {
                    if ($network->status == 'ACTIVE') {
                        if ($network->{"router:external"} != true) {
                            $data[$cloudLocation][] = array(
                                'id' => $network->id,
                                'name' => $network->name
                            );
                        }
                    }
                }
            }
        }

        $this->response->data(array('data' => $data));
    }

    public function xGetCloudLocationsAction($platform)
    {
        $locations = self::loadController('Platforms')->getCloudLocations($platform, false);
        $this->response->data(array('locations' => $locations));
    }

}
