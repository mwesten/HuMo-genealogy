<?php

namespace Genealogy\App\Controller;

use Genealogy\App\Model\ChatGenealogyModel;

class ChatGenealogyController
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function process()
    {
        //$chatGenealogyModel = new ChatGenealogyModel($this->config);

        //return $chatGenealogyModel->handleRequest();
    }
}
