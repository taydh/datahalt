<?php
namespace Taydh\Datahalt\Servant;

class BackendServant
{
    private $backendId;
    private $config;
    private $clientId;
    private $extractSessionClaimsFunctionName;
    private $sessionClaims;

    public function __construct( $backendId )
    {
        $this->backendId = $backendId;
        $this->config = $this->readBackendConfig();
        $this->clientId = $this->config['clientId'];
        $this->extractSessionClaimsFunctionName = $this->config['function.extractSessionClaims'];
    }

    private function readBackendConfig ()
    {
		return parse_ini_file("{$_ENV['datahalt.config_dir']}/backends/{$this->backendId}/backend.ini");
	}
	
	private function readQueryTemplate( $groupName, $templateName )
    {
		return @file_get_contents("{$_ENV['datahalt.config_dir']}/backends/{$this->backendId}/query-templates/{$groupName}/tmpl.{$templateName}.json");
	}

    public function process ( $group, $action, $params )
    {
        $fnRealpath = realpath("{$_ENV['datahalt.function_dir']}/{$this->extractSessionClaimsFunctionName}.php");
        $isFnValid = $fnRealpath && strpos($fnRealpath, $_ENV['datahalt.function_dir']) === 0;

        if (!$isFnValid) {

        }

        $fn = include($fnRealpath);
        $sessionClaims = $fn();
        $queryTemplate = $this->readQueryTemplate($group, $action);
        $queryObject = json_decode($queryTemplate);

        /* 
            important!
            
            sessionClaims must be merged after external parameters to avoid overridden by requester
        */
        
        $queryObject->variables = (object) array_merge($params, $sessionClaims);

        $clientSettings = EndpointServant::readClientSettings($this->clientId);
        $queryRunner = new \Taydh\TeleQuery\QueryRunner($clientSettings);

		return $queryRunner->run($queryObject);
    }
}