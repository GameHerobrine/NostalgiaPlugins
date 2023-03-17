<?php

namespace de\tvorok\minigames\games;

//use de\tvorok\minigames\MGconfig;
use de\tvorok\minigames\MGmain;
//use de\tvorok\minigames\MGplayer;
use de\tvorok\minigames\gameSession;
use Player;
//use ReflectionClass;
use ServerAPI;
use Vector3;

class ObstacleRace extends MGdummyGame{
    public function __construct(ServerAPI $api, $server = false){
        parent::__construct($api, false);
        /*$magikClass = new ReflectionClass("Block");
        $this->magikProperty = $magikClass->getProperty("id");
        $this->magikProperty->setAccessible(true);*/
    }
    
    public function setFields(){
        $this->config = $this->mgConfig->getGameConfig($this->path);
        if(!isset($this->config["fields"])){
            $this->fields = false;
            return;
        }
        foreach($this->config["fields"] as $fieldName => $array){
            unset($array);
            $this->fields[$fieldName] = [
                "players" => [],
                "status" => false,
                "name" => "$fieldName",
                //"backup" => [],
                "level" => $this->config["fields"][$fieldName]["level"],
                "maxPlayers" => $this->config["fields"][$fieldName]["maxPlayers"]
            ];
        }
    }
    
    public function updateField($field){
        $this->fields[$field->getName()] = $field->updateData();
    }
    
    public function handler($data, $event){
        if($this->fields == false){
            return;
        }
        
        if($data instanceof Player){
            $user = $data->username;
        }
        if($event == "player.move"){
            $user = $data->player->username;
        }
        if(!isset($user)){
            $user = $data["player"]->username;
        }
        
        $fieldName = MGmain::playerInField($user, $this->fields); //in field?
        if($fieldName == false){
            return;
        }
        $field = $this->sessions[$fieldName];
        $status = $field->getStatus();
        
        switch($event){
            case "player.move":
                $downBlock = $data->level->getBlock(new Vector3($data->x, $data->y-1, $data->z));
                if($downBlock->getID() === WOOL and $downBlock->getMetadata === 14){
                    $this->finish([$user, $field]);
                }
                break;
            case "player.block.break":
                if($status == "game"){
                    /*if(in_array($data["target"]->getID(), [LEAVES])){
                        $field->addBackup($data["target"]);
                        $data["target"]->onBreak($data["item"], $data["player"]);
                        $this->magikProperty->setValue($data["target"], 0);
                        return true;
                    }*/
                    return false;
                }
                return false;
            case "player.quit":
                if($status == "game"){
                    $this->loserProcess($data, $event, $fieldName);
                    $this->mgPlayer->broadcastForWorld($field->getLevelName(), "$user quit.");
                }
                if($status == "lobby" or $status == "start"){
                    $field->removePlayer($user);
                    $this->updateField($field);
                }
                break;
            case "player.interact":
                return false;
            case "player.block.place":
                return false;
            case "hub.teleport":
                $field->removePlayer($user);
                $this->updateField($field);
                $this->mgPlayer->confiscateItems($this->api->player->get($user));
                if($status == "game"){
                    $this->checkForWin($field);
                }
                $data["player"]->sendChat("You leave ".$this->gameName." game!");
                break;
        }
    }
    
    public function command($cmd, $args, $issuer, $alias){
        if(!($issuer instanceof Player)){
            return "Please run command in game.";
        }
        if(!isset($args[0]) or $args[0] === ""){
            if($this->api->ban->isOp($issuer->username)){
                return "/$cmd join <fieldName>\n/$cmd wins\n/$cmd setfield <fieldName> [maxPlayers]\n/$cmd setlobby <fieldName>\n/$cmd setpos1 <fieldName>\n/$cmd setpos2 <fieldName>";
            }
            return "/$cmd join <fieldName>\n/$cmd wins";
        }
        $output = "";
        switch($args[0]){
            case "wins":
                return $this->mgConfig->getWins($issuer->username, $this->gameName)." wins";
            case "join":
                if(!isset($args[1]) or $args[1] === ""){
                    return "/$cmd join <field>";
                }
                $fieldName = $args[1];
                if(!isset($this->config["fields"][$fieldName])){
                    return "/this field doesn't exist!";
                }
                if($issuer->level->getName() != $this->mgConfig->getMainConfig()["hub"]["level"]){
                    return "/you need to be in hub to join!";
                }
                if(MGmain::playerInField($issuer->username, $this->fields) != false){
                    return "/you already in field!";
                }
                if(!isset($this->sessions[$fieldName])){ //start code
                    $this->startField($fieldName);
                    //$output .= "/starting field \"$fieldName\"\n";
                }
                $msg = $this->mgPlayer->joinField($this->sessions[$fieldName], $issuer, $this->config["fields"][$fieldName], $this->gameName);
                $this->updateField($this->sessions[$fieldName]);
                return $msg;
            default:
                if($this->api->ban->isOp($issuer->username)){
                    $output = $this->opCommand($cmd, $args, $issuer);
                }
        }
        return $output;
    }
    
    public function opCommand($cmd, $args, $issuer){
        if(isset($args[1]) and $args[1] !== ""){
            $fieldName = $args[1];
        }
        else{
            return "/$cmd ".$args[0]." <fieldName>";
        }
        switch($args[0]){
            case "setfield":
                if(!isset($args[2]) or $args[2] == ""){
                    $maxPlayers = 12;
                }
                else{
                    $maxPlayers = $args[2];
                }
                $this->mgConfig->fieldIntoConfig($this->path, $fieldName, [
                    "level" => $issuer->entity->level->getName(),
                    "maxPlayers" => $maxPlayers
                ]);
                $this->setFields();
                return "/field $fieldName created";
                //todo delfield
            case "setpos1":
            case "setpos2":
            case "setlobby":
                $output = $this->mgConfig->posIntoConfig($issuer, $fieldName, substr($args[0], 3), $this->path);
                if($output){
                    $this->setFields();
                    return "/".$args[0]." seted";
                }
                else{
                    return $output;
                }
            case "start":
                return "/use /$cmd join <fieldName>";
        }
    }
    
    //stages
    public function startField($fieldName = ""){
        if($fieldName == ""){
            $fieldName = array_rand(MGmain::getAvailableFields($this->fields));
        }
        $config = $this->config["fields"][$fieldName];
        if(!isset($config)){
            console("this field doesn't exist!");
            return;
        }
        if(!isset($this->config["fields"][$fieldName]["lobby"])){
            console("$fieldName lobby not found!");
            return;
        }
        if(!isset($this->config["fields"][$fieldName]["pos1"]) and !isset($this->config["fields"][$fieldName]["pos2"])){
            console("$fieldName where pos1 or pos2!!!");
            return;
        }
        $field = new gameSession($this->api, $this->fields[$fieldName]);
        $this->sessions[$fieldName] = $field;
        $field->setStatus("start");
        $this->updateField($field);
        $this->lobby($field);
    }
    
    public function lobby($field){
        $fieldName = $field->getName();
        $field->setStatus("lobby");
        $this->updateField($field);
        $this->api->time->set(0, $this->api->level->get($field->getLevelName())); //fix
        $this->api->chat->broadcast(ucfirst($this->gameName)." \"$fieldName\" will start in ".MGmain::formatTime($this->config["lobbyTime"]));
        $field->timer($this->config["lobbyTime"], "The game starts in");
        $this->api->schedule($this->config["lobbyTime"] * 20, [$this, "game"], $field);
    }
    
    public function game($field){
        $players = $field->getPlayers();
        if(count($players) < 2){
            $this->mgPlayer->broadcastForWorld($field->getLevelName(), ucfirst($this->gameName)." cannot run, need 2 players!");
            $this->restoreField($field); //todo schedule
            return;
        }
        else{
            foreach($players as $username){
                $this->api->player->get($username)->addItem(DIAMOND_SHOVEL, 0, 1);
            }
            $this->mgPlayer->teleportAll("spawnpoint", $players, $this->config, $field->getName());
            $field->setStatus("game");
            $this->updateField($field);
            $this->api->chat->broadcast(ucfirst($this->gameName)." \"".$field->getName()."\" has been started!");
        }
    }
    
    public function finish($array){
        $winner = $array[0];
        $field = $array[1];
        $field->setStatus("finish");
        $this->mgConfig->addWin($winner, $this->gameName);
        $this->api->chat->broadcast("$winner win in ".$this->gameName." \"".$field->getName()."\"!");
        $this->mgPlayer->confiscateItems($this->api->player->get($winner));
        $this->restoreField($field);
    }
    
    public function end($level){
        $players = $this->api->player->getAll($this->api->level->get($level));
        foreach($players as $player){
            $this->mgPlayer->tpToHub($player->username);
        }
    }
    
    public function restoreField($field){
        $this->mgPlayer->broadcastForWorld($field->getLevelName(), "You will teleported to hub!");
        $this->api->schedule(30, [$this, "end"], $field->getLevelName());
        //console("was break ".count($field->getBackup())." blocks");
        if(count($field->getBackup()) > 0){
            $blocks = $field->getBackup();
            foreach($blocks as $block){
                $block->level->setBlockRaw($block, $block);
            }
        }
        $field->restoreData();
        $this->updateField($field);
        unset($this->sessions[$field->getName()]);
        unset($field);
    }
    
    public function checkForWin($field){
        $players = $field->getPlayers();
        $surv = count($players);
        if($surv > 1){
            $this->mgPlayer->broadcastForWorld($field->getLevelName(), "$surv players remaining.");
        }
        elseif($surv = 1){
            $winner = array_shift($players);
            $this->api->schedule(1, [$this, "finish"], [$winner, $field]);
        }
    }
    
    public function loserProcess($data, $fieldName){
        $field = $this->sessions[$fieldName];
        $user = $data->username;
        $field->removePlayer($user);
        $this->updateField($field);
        $this->checkForWin($field);
    }
}