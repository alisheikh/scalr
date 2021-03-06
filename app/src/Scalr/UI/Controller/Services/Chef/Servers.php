<?php
use Scalr\Acl\Acl;

class Scalr_UI_Controller_Services_Chef_Servers extends Scalr_UI_Controller
{
    public function defaultAction()
    {
        $this->viewAction();
    }

    public function viewAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_SERVICES_CHEF);
        $this->response->page('ui/services/chef/servers/view.js');
    }

    public function editAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_SERVICES_CHEF);
        $servParams = $this->db->GetRow('SELECT env_id, url, auth_key as authKey, username as userName, v_auth_key as authVKey, v_username as userVName
            FROM services_chef_servers WHERE id = ?', array($this->getParam('servId')));

        if (!$this->user->getPermissions()->hasAccessEnvironment($servParams['env_id']))
            throw new Scalr_Exception_InsufficientPermissions();

        $servParams['authKey'] = $this->getCrypto()->decrypt($servParams['authKey'], $this->cryptoKey);
        $servParams['authVKey'] = $this->getCrypto()->decrypt($servParams['authVKey'], $this->cryptoKey);
        $this->response->page('ui/services/chef/servers/create.js', array('servParams'=>$servParams));
    }

    public function createAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_SERVICES_CHEF);
        $this->response->page('ui/services/chef/servers/create.js');
    }

    public function xListServersAction()
    {
        $response = $this->buildResponseFromSql('SELECT id, url, username FROM services_chef_servers WHERE env_id = '.$this->getEnvironmentId());
        $this->response->data($response);
    }

    public function xListEnvironmentsAction()
    {
        $servParams = $this->db->GetRow('SELECT id, url, env_id, auth_key as authKey, username as userName FROM services_chef_servers WHERE id = ?', array($this->getParam('servId')));

        if(!$this->user->getPermissions()->hasAccessEnvironment($servParams['env_id']))
            throw new Scalr_Exception_InsufficientPermissions();

        $chef = Scalr_Service_Chef_Client::getChef($servParams['url'], $servParams['userName'], $this->getCrypto()->decrypt($servParams['authKey'], $this->cryptoKey));
        $response = $chef->listEnvironments();
        if ($response instanceof stdClass)
            $response = (array)$response;
        $envs = array();
        foreach ($response as $key => $value)
            $envs[]['name'] = $key;

        $this->response->data(array('data' => $envs));
    }

    public function xDeleteServerAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_SERVICES_CHEF);
        $sql = 'SELECT name FROM services_chef_runlists WHERE chef_server_id = '.$this->db->qstr($this->getParam('servId'));
        $result = $this->buildResponseFromSql($sql);
        if($result['total'])
            $this->response->failure('This chef server is in use by runlist(s). It can\'t be deleted');
        else {
            $this->db->Execute('DELETE FROM services_chef_servers WHERE id = ? AND env_id = ?', array($this->getParam('servId'), $this->getEnvironmentId()));
            $this->response->success('Chef server successfully deleted');
        }
    }

    public function xSaveServerAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_SERVICES_CHEF);
        $servId = $this->getParam('servId');

        $key = str_replace("\r\n", "\n", $this->getParam('authKey'));
        $vKey = str_replace("\r\n", "\n", $this->getParam('authVKey'));

        $chef = Scalr_Service_Chef_Client::getChef($this->getParam('url'), $this->getParam('userName'), $key);
        $response = $chef->listCookbooks();
        $chef2 = Scalr_Service_Chef_Client::getChef($this->getParam('url'), $this->getParam('userVName'), $vKey);
        $response = $chef2->createClient('scalr-temp-client');
        $response2 = $chef->removeClient('scalr-temp-client');

        if ($servId) {
            $this->db->Execute('UPDATE services_chef_servers SET  `url` = ?, `username` = ?, `auth_key` = ?, `v_username` = ?, `v_auth_key` = ? WHERE `id` = ? AND env_id = ?', array(
                $this->getParam('url'),
                $this->getParam('userName'),
                $this->getCrypto()->encrypt($key, $this->cryptoKey),
                $this->getParam('userVName'),
                $this->getCrypto()->encrypt($vKey, $this->cryptoKey),
                $servId,
                $this->getEnvironmentId()
            ));
        } else {
            $this->db->Execute('INSERT INTO services_chef_servers (`env_id`, `url`, `username`, `auth_key`, `v_username`, `v_auth_key`) VALUES (?, ?, ?, ?, ?, ?)', array(
                $this->getEnvironmentId(),
                $this->getParam('url'),
                $this->getParam('userName'),
                $this->getCrypto()->encrypt($key, $this->cryptoKey),
                $this->getParam('userVName'),
                $this->getCrypto()->encrypt($vKey, $this->cryptoKey),
            ));
            $servId = $this->db->Insert_ID();
        }

        $this->response->data(array(
            'server' => array(
                'id' => (string)$servId,
                'url' => $this->getParam('url')
            )
        ));
        $this->response->success('Server successfully saved');
    }
}