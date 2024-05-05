<?php
namespace Taydh\Datahalt\Servant;

class BackendServant
{ 
    private $backendId;
    private $config;
    private $clientId;
    private $backendDir;
    private $extractClaimsFunction;
    private $sessionClaims;

   public function __construct( $backendId )
    {
        $this->backendId = $backendId;
        $this->config = $this->readBackendConfig();
        $this->clientId = $this->config['clientId'];
        $this->backendDir = realpath($this->config['backendDir']);
        $this->extractClaimsFunction = $this->config['extractClaimsFunction'] ?? '';

        if (!$this->backendDir) {
            throw new \Exception('Backend directory not found:' . $this->config['backendDir']);
        }
    }

    private function readBackendConfig ()
    {
		return parse_ini_file("{$_ENV['datahalt.config_dir']}/backends/{$this->backendId}.ini");
	}
	
	private function readQueryTemplate( $groupName, $templateName )
    {
		return @file_get_contents("{$this->backendDir}/{$groupName}/{$templateName}.json");
	}

    public function process ( $group, $action, $externalArgs )
    {
        $sessionClaims = [];

        if ($this->extractClaimsFunction) {
            $fnRealpath = realpath("{$_ENV['datahalt.function_dir']}/{$this->extractClaimsFunction}.php");
            $isFnValid = $fnRealpath && strpos($fnRealpath, realpath($_ENV['datahalt.function_dir'])) === 0;

            if (!$isFnValid) {
                throw new \Exception('Function not found: ' . $this->extractClaimsFunction);
            }

            $fn = include($fnRealpath);
            $backendArgs = [
                'backendId' => $this->backendId,
                'config' => $this->config,
                'clientId' => $this->clientId,
                'backendDir' => $this->backendDir,
                'bearerToken' => \Taydh\Common::getBearerToken(),
            ];
            $sessionClaims = $fn($backendArgs);
        }

        $queryTemplate = $this->readQueryTemplate($group, $action);
        $queryObject = json_decode($queryJson);

        $source = [];
        foreach ($externalArgs as $key => $val) $source["arg.$key"] = $val;
        foreach ($sessionClaims as $key => $val) $source["claim.$key"] = $val;

        $clientSettings = EndpointServant::readClientSettings($this->clientId);
        $queryRunner = new \Taydh\Telequery\QueryRunner($clientSettings);
        $result = $queryRunner->run($queryObject, $source);

		return $result;
    }
}