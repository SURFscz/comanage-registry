<?php
/**
 * COmanage Provisioning Shell
 *
 * Portions licensed to the University Corporation for Advanced Internet
 * Development, Inc. ("UCAID") under one or more contributor license agreements.
 * See the NOTICE file distributed with this work for additional information
 * regarding copyright ownership.
 *
 * UCAID licenses this file to you under the Apache License, Version 2.0
 * (the "License"); you may not use this file except in compliance with the
 * License. You may obtain a copy of the License at:
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 * 
 * @link          http://www.internet2.edu/comanage COmanage Project
 * @package       registry
 * @since         COmanage Registry v3.2, SCZ fork
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

  class ProvisionShell extends AppShell {
  var $uses = array('Co',
                    'CoProvisioningTarget',
                    'CoPerson',
                    'CoGroup',
                    'CoEmailList',
                    'CoService');

  public function getOptionParser() {
    $parser = parent::getOptionParser();
    
    $parser->addOption(
      'coid',
      array(
        'short' => 'c',
        'help' => _txt('sh.pr.arg.coid'),
        'boolean' => false,
        'default' => false
      )
    )->addOption(
      'provisioner',
      array(
        'short' => 'p',
        'help' => _txt('sh.pr.arg.provisioner'),
        'boolean' => false,
        'default' => false
      )
    )->addOption(
      'type',
      array(
        'short' => 't',
        'help' => _txt('sh.pr.arg.type'),
        'boolean' => false,
        'default' => false
      )
    )->epilog(_txt('sh.pr.arg.epilog'));
    
    return $parser;
  }
  
  function provision($co, $coProvisioningTarget) {
    
    foreach(array('CoPerson','CoGroup','CoEmailList','CoService') as $sModel) {
      // Attach ProvisionerBehavior
      $this->CoProvisioningTarget->Co->$sModel->Behaviors->load('Provisioner');
      
      switch($sModel) {
      case "CoGroup":
        $sAction = ProvisioningActionEnum::CoGroupReprovisionRequested;
        break;
      case "CoEmailList":
        $sAction = ProvisioningActionEnum::CoEmailListReprovisionRequested;
        break;
      case "CoService":
        $sAction = ProvisioningActionEnum::CoServiceReprovisionRequested;
        break;
      case "CoPerson":
      default:
        $sAction = ProvisioningActionEnum::CoPersonReprovisionRequested;
        break;
      }

      $args = array();
      $args['conditions'][$sModel.'.co_id'] = $co['Co']['id'];
      $args['fields'] = array($sModel.'.id', $sModel.'.status');
      $args['order'] = array($sModel.'.id' => 'asc');
      $args['contain'] = false;
      
      $models = $this->CoProvisioningTarget->Co->{$sModel}->find('all', $args);
      foreach($models as $model) {    
        // manually invoke provisioning
        $sId=null;
        $sStatus=null;
     
        try {
          $sId = $model[$sModel]['id'];
          $sStatus = $model[$sModel]['status'];
          $this->CoProvisioningTarget->Co->$sModel->manualProvision($coProvisioningTarget['CoProvisioningTarget']['id'],
                                                                  ($sModel == 'CoPerson' ? $sId : null),
                                                                  ($sModel == 'CoGroup' ? $sId : null),
                                                                  $sAction,
                                                                  ($sModel == 'CoEmailList' ? $sId : null),
                                                                  null, // CoGroupMemberId
                                                                  ($sModel == 'CoService' ? $sId : null));
        }
        catch(InvalidArgumentException $e) {
          switch($e->getMessage()) {
            case _txt('er.cop.unk'):
              // unknown model, should only occur if it is deleted while we are provisioning
              $this->out(_txt('sh.pr.warn.unknown',array($sModel,$sId,$sStatus)));
              break;
            case _txt('er.copt.unk'):
              // should only occur if a target is removed while we are provisioning
              $this->out(_txt('sh.pr.warn.unknown',array('CoProvisioningTarget',$coProvisioningTarget['CoProvisioningTarget']['id'],'')));
              break;
            default:
              $this->out(_txt('sh.pr.warn.unknown2',array($sModel,$sId,$sStatus, $e->getMessage())));
              break;
          }
        }
        catch(RuntimeException $e) {
          $this->out(_txt('sh.pr.warn.unknown2',array($sModel,$sId,$sStatus, $e->getMessage())));
        }
      }
    }    
  }  
  
  function main() {
    _bootstrap_plugin_txt();

    // Provision all, or selected COs to all, or selected, provisioners
    $selected_co = intval($this->params['coid']);
    $selected_prov = $this->params['provisioner'];
    $selected_plugin = $this->params['type'];

    $args=array();
    $args['contain']=false;
    if($selected_co >0) {
      $args['conditions']['Co.id'] = $selected_co;
    }
    $cos = $this->Co->find('all',$args);

    if(empty($cos)) {
      $this->out(_txt('sh.pr.err.noco'));
      return;
    }

    foreach($cos as $co) {
      $args=array();
      $args['contain']=false;
      $args['conditions']['CoProvisioningTarget.co_id'] = $co['Co']['id'];
      if(!empty($selected_prov)) {
        $args['conditions']['CoProvisioningTarget.description'] = $selected_prov;
      }
      if(!empty($selected_plugin)) {
        $args['conditions']['CoProvisioningTarget.plugin'] = $selected_plugin;
      }
      $provisioners = $this->CoProvisioningTarget->find('all',$args);      

      if(empty($provisioners)) {
        $this->out(_txt('sh.pr.warn.noprov', array($co['Co']['name'], $co['Co']['id'])));
        continue;
      }

      foreach($provisioners as $provisioner) {
        $this->out(_txt('sh.pr.action', array($co['Co']['name'], $co['Co']['id'], $provisioner['CoProvisioningTarget']['description'],$provisioner['CoProvisioningTarget']['plugin'])));
        $this->provision($co, $provisioner);
      }
    }

    $this->out(_txt('sh.pr.done'));
  }
}
