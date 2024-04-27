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
        $this->extractSessionClaimsFunctionName = $this->config['function.extractSessionClaims'] ?? '';
    }

    private function readBackendConfig ()
    {
		return parse_ini_file("{$_ENV['datahalt.config_dir']}/backends/{$this->backendId}/backend.ini");
	}
	
	private function readQueryTemplate( $groupName, $templateName )
    {
		return @file_get_contents("{$_ENV['datahalt.config_dir']}/backends/{$this->backendId}/query/{$groupName}/{$templateName}.json");
	}

    public function process ( $group, $action, $externalArgs )
    {
        $sessionClaims = [];

        if ($this->extractSessionClaimsFunctionName) {
            $fnRealpath = realpath("{$_ENV['datahalt.function_dir']}/{$this->extractSessionClaimsFunctionName}.php");
            $isFnValid = $fnRealpath && strpos($fnRealpath, $_ENV['datahalt.function_dir']) === 0;

            if (!$isFnValid) {

            }

            $fn = include($fnRealpath);
            $backendProps = [
                'backendId' => $this->backendId,
                'config' => $this->config,
                'clientId' => $this->clientId,
            ];
            $sessionClaims = $fn($backendProps);
        }
        
        $m = new \Mustache_Engine(array('entity_flags' => ENT_QUOTES));

        $queryTemplate = $this->readQueryTemplate($group, $action);
        $queryJson = $m->render($queryTemplate, ['args' => $externalArgs, 'claims' => $sessionClaims]);
        $queryObject = json_decode($queryJson);

        $clientSettings = EndpointServant::readClientSettings($this->clientId);
        $queryRunner = new \Taydh\TeleQuery\QueryRunner($clientSettings);

		return $queryRunner->run($queryObject);
    }
}