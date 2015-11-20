<?php
/*

Dernière modification  : 20/11/2015

*/

include('php-riot-api.php');
include('FileSystemCache.php');

$platform=array(
	"na" => "NA1",
	"euw" => "EUW1",
	"eune" => "EUN1",
	"br" => "BR1",
	"lan" => "LA1",
	"las" => "LA2",
	"oce" => "OC1",
	"ru" => "RU1",
	"tr" => "TR1"
);


$messageSumm=array(
	"NOT_FOUND" => "Invocateur non trouvé"
);

$messageGame=array(
	"NOT_FOUND" => "L'Invocateur n'a pas fait de parties classées"
);

//AngularJS balance les données passées en POST dans l'input, donc bricolage obligatoire
$_POST=json_decode(file_get_contents('php://input'),true);
// if(true){
if(isset($_POST['servor']) && isset($_POST['summoner'])){
	$server=$_POST['servor'];
	$summoner=$_POST['summoner'];
	
	// $server="euw";
	// $summoner="paigeounette";
	
	$api = new riotapi($server,new FileSystemCache('cache/'));


	try {
		//Recherche de l'invocateur par son nom
		$r = $api->getSummonerByName($summoner);
		
		foreach($r as $r)$id = $r['id'];
			
			try {
				//Recherche de la liste des match en SoloQ
				$r = $api->getMatchList($id,"RANKED_SOLO_5x5");
				// /*
				
				//Initialisation des variables et des tableaux;
				$stop=0;
				
				$arrayIds=array();
				
				$total=array();
				$total['nbMatches']=0;
				$total['AFK']=0;
				$total['AFKtargetTeam']=0;
				$total['AFKotherTeam']=0;
				$total['TargetTeam']=array("1"=>0,"2"=>0,"3"=>0);
				$total['OtherTeam']=array("1"=>0,"2"=>0,"3"=>0);
				$total['GamesTargetTeam']=0;
				$total['GamesOtherTeam']=0;
				$total['GamesWithAFK']=0;
				$total["ownAFK"]=0;
				$total["tabOwnAFK"]=array();
				$total["tabAFKtargetTeam"]=array();
				$total["tabAFKotherTeam"]=array();
				
				//Sélection des 100 parties les plus récentes
				foreach($r['matches'] as $match){
					array_push($arrayIds,$match['matchId']);
					$stop++;
					if($stop==100)
					break;
				}
				
				//Recherche des détails des parties sélectionnées
				$games=$api->getLongHistory($arrayIds);
				
				
				//Traitement partie par partie
				foreach($games as $s){
					if(!is_array($s))$s=json_decode($s,true);
					
					
					//Recherche du participantId de l'invocateur recherché
					if(isset($s['participantIdentities']))
					foreach($s['participantIdentities'] as $participantIdentities){
						if($participantIdentities['player']['summonerId']==$id)$targetId = $participantIdentities['participantId'];
					}
					
					$afkInGameTargetTeam=0;
					$afkInGameOtherTeam=0;
					$ownAFK=false;
					
					$total['nbMatches']++;
					if(isset($s['timeline'])){
						
						//Evaluation participant par participant dans la timeline
						foreach($s['timeline']['frames'][0]['participantFrames'] as $participantId=>$participant){
							$frame=0;
							$s['participants'][$participantId-1]['minutesAFK']=0;
							while(isset($s['timeline']['frames'][$frame])){
								//Recherche de l'AFK
								$result=detectAFK($s['timeline']['frames'], $frame, $participantId, 0);
								
								//AFK détecté
								if($result['minutes']>=3){
								
									$s['participants'][$participantId-1]['minutesAFK']+=$result['minutes'];
									$s['participants'][$participantId-1]['isAFK']=true;
									if($targetId == $participantId)$ownAFK=true;
								
								}
								
								//Passage à la frame suivante
								$frame=$result['frame']+1;
							}
							
						}
						
						
						$targetTeam=$s['participants'][$targetId]['teamId'];
						
						//Ajout de l'AFK éventuel de l'invocateur recherché
						if($ownAFK){
							$total["ownAFK"]++;
							array_push($total["tabOwnAFK"],$s['matchId']);
						}
						
						//Affectation des données pour chaque participant
						foreach($s['participants'] as $participant){
							
							//Si équipe alliée
							if($participant['teamId']==$targetTeam){
								
								//Si le participant est AFK
								if(isset($participant['isAFK'])){
									$total['AFKtargetTeam']++;
									array_push($total["tabAFKtargetTeam"],$s['matchId']);
									$afkInGameTargetTeam=1;
									
									if($participant['minutesAFK']<5){
										$total["TargetTeam"]["1"]++;
									}else if($participant['minutesAFK']<20){
										$total["TargetTeam"]["2"]++;
									}else{
										$total["TargetTeam"]["3"]++;
									}
								}
								
							}else{
								
								if(isset($participant['isAFK'])){
									$total['AFKotherTeam']++;
									array_push($total["tabAFKotherTeam"],$s['matchId']);
									$afkInGameOtherTeam=1;
									
									if($participant['minutesAFK']<5){
										$total["OtherTeam"]["1"]++;
									}else if($participant['minutesAFK']<20){
										$total["OtherTeam"]["2"]++;
									}else{
										$total["OtherTeam"]["3"]++;
									}
								}
								
							}
							
						}
					}
					
					//Données du nombre de parties concernées par un AFK
					if($afkInGameTargetTeam==1)$total['GamesTargetTeam']++;
					if($afkInGameOtherTeam==1)$total['GamesOtherTeam']++;
					if($afkInGameOtherTeam==1 || $afkInGameTargetTeam==1)$total['GamesWithAFK']++;
				}
				
				//Calculs des pourcentage, c'est toujours la classe
				$total['AFK']=$total['AFKtargetTeam']+$total['AFKotherTeam'];
				if($total['nbMatches']!=0){
					$total['percentAFKtargetTeam']=round($total['AFKtargetTeam']/($total['nbMatches']*5)*100,2);
					$total['percentAFKotherTeam']=round($total['AFKotherTeam']/($total['nbMatches']*5)*100,2);
					$total['percentAFK']=round($total['AFK']/($total['nbMatches']*10)*100,2);
				}else{
					$total['percentAFKtargetTeam']=0;
					$total['percentAFKotherTeam']=0;
					$total['percentAFK']=0;
				}
				
				echo json_encode($total);
				// */
				
				
			} catch(Exception $e) {
				$error=$messageGame[$e->getMessage()];
				echo json_encode(array("error" => $error));
			};
	} catch(Exception $e) {
		$error=$messageSumm[$e->getMessage()];
		echo json_encode(array("error" => $error));
	};

}


//Fonction de détection d'AFK
//	prend en entrée la timeline, la frame actuelle, le participantId du joueur ciblé et le nombre de minutes déjà AFK
//	sors la dernière frame contrôlée et les minutes passé AFK
function detectAFK($frames, $frame, $id, $minutes){
	if(isset($frames[$frame]) && isset($frames[$frame-1])){
		
		//Si la position actuelle est la même que la précédente
		//	on incrémente les minutes passé AFK et on passe à la frame suivante
		//Sinon on renvoie la frame atteinte et les minutes passé AFK
		if($frames[$frame-1]['participantFrames'][$id]['position']==$frames[$frame]['participantFrames'][$id]['position']){
			
			return detectAFK($frames, $frame+1, $id, $minutes+1);
			
		}else{
			
			return array("frame"=>$frame,"minutes"=>$minutes);
			
		}
		
	}else{
	
		return array("frame"=>$frame,"minutes"=>$minutes);
		
	}
	
}
?>