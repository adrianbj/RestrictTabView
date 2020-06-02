<?php

/**
 * ProcessWire module for restricting access to Page Edit tabs via permissions
 * by Adrian Jones
 *
 * Determine how pages are renamed when the title is changed
 *
 * Copyright (C) 2020 by Adrian Jones
 *
 */

class RestrictTabView extends WireData implements Module, ConfigurableModule {

    public static function getModuleInfo() {
        return array(
            'title' => 'Restrict Tab View',
            'summary' => 'Restrict access to Page Edit tabs via permissions',
            'author' => 'Adrian Jones',
            'href' => 'http://modules.processwire.com/modules/restrict-tab-view/',
            'version' => '1.2.2',
            'autoload' => 'template=admin',
            'requires' => 'ProcessWire>=2.5.16',
            'icon'     => 'toggle-on'
        );
    }


    protected $data = array();

    protected $hiddenTabs = array();


   /**
     * Default configuration for module
     *
     */
    static public function getDefaultData() {
        return array(
            "viewTabs" => array(),
            "hideTabs" => array(),
            "specifiedTemplates" => array(),
            "showNameInContentTab" => false
        );
    }

    /**
     * Populate the default config data
     *
     */
    public function __construct() {
        foreach(self::getDefaultData() as $key => $value) {
            $this->$key = $value;
        }
    }


    public function init() {
        if($this->wire('user')->isSuperuser()) return;
        $this->wire()->addHookAfter('ProcessPageEdit::loadPage', function(HookEvent $event) {
            $pid = $event->arguments[0];
            $this->getHiddenTabs($pid);
        });
        $this->wire()->addHookBefore('ProcessPageEdit::buildFormContent', $this, "beforeBuildFormContent");
        $this->wire()->addHookAfter('ProcessPageEdit::buildForm', $this, "afterBuildForm");
        $this->wire()->addHookAfter('ProcessPageEdit::execute', function(HookEvent $event) {
            if(in_array('View', $this->hiddenTabs)) {
                $event->return .= '
                <script>
                    $(document).ready(function() {
                        $("#_ProcessPageEditViewDropdown").remove();
                    });
                </script>';
            }
        });
    }


    protected function afterBuildForm(HookEvent $event) {
        foreach($this->hiddenTabs as $tab) {
            $this->removeTabs($tab, $event);
        }
    }


    protected function beforeBuildFormContent(HookEvent $event) {
        // if settings tab is hidden for this user and name field is not set to be in the content tab, then we need to add it hidden
        if(
            (in_array("Settings", $this->data['viewTabs']) && !$this->wire('user')->hasPermission("tab-settings-view")) ||
            (in_array("Settings", $this->data['hideTabs']) && $this->wire('user')->hasPermission("tab-settings-hide"))
        ) {

            $p = $event->object->getPage();
            if(!$this->data['specifiedTemplates'] || in_array($p->template->id, $this->data['specifiedTemplates'])) {
                if(!$p->template->nameContentTab) $p->template->nameContentTab = 1;
            }

        }
    }

    private function getHiddenTabs($pid) {
        $p = $this->wire('pages')->get($pid);
        if(!$this->data['specifiedTemplates'] || in_array($p->template->id, $this->data['specifiedTemplates'])) {
            foreach($this->data['viewTabs'] as $tab) {
                if(!$this->wire('user')->hasPermission("tab-".strtolower($tab)."-view")) {
                    $this->hiddenTabs[] = $tab;
                }
            }

            foreach($this->data['hideTabs'] as $tab) {
                if($this->wire('user')->hasPermission("tab-".strtolower($tab)."-hide")) {
                    $this->hiddenTabs[] = $tab;
                }
            }
        }
    }


    private function removeTabs($tab, $event) {

        $form = $event->return;

        if($tab == "Settings") {
            if(!$this->data['showNameInContentTab']) {
                $pn = $form->getChildByName('_pw_page_name');
                if($pn instanceof Inputfield) {
                    $pn->wrapAttr('style', 'display:none;');
                }
            }
        }

        if($tab == "Settings" || $tab == "Children" || $tab == "Restore" || $tab == "Delete") {
            $fieldset = $form->find("id=ProcessPageEdit".$tab)->first();
        }
        else {
            $fieldset = $form->find("id=ProcessPageEdit".$tab);
        }
        if(!is_object($fieldset)) return;

        $form->remove($fieldset);
        $event->object->removeTab("ProcessPageEdit".$tab);
    }


    /**
     * Return an InputfieldsWrapper of Inputfields used to configure the class
     *
     * @param array $data Array of config values indexed by field name
     * @return InputfieldsWrapper
     *
     */
    public function getModuleConfigInputfields(array $data) {

        $data = array_merge(self::getDefaultData(), $data);

        $wrapper = new InputfieldWrapper();

        $f = $this->wire('modules')->get('InputfieldCheckboxes');
        $f->attr('name+id', 'viewTabs');
        $f->label = __('View Tabs');
        $f->description = __("For non-superusers, the selected tabs will not be viewable unless they have a permission named tab-tabname-view, eg: tab-settings-view");
        $f->addOption("Children");
        $f->addOption("Settings");
        $f->addOption("Restore");
        $f->addOption("Delete");
        $f->addOption("View");
        if(isset($data['viewTabs'])) $f->value = $data['viewTabs'];
        $wrapper->add($f);

        $f = $this->wire('modules')->get('InputfieldCheckboxes');
        $f->attr('name+id', 'hideTabs');
        $f->label = __('Hide Tabs');
        $f->description = __("For non-superusers, the selected tabs will be hidden if they have a permission named tab-tabname-hide, eg: tab-settings-hide");
        $f->addOption("Children");
        $f->addOption("Settings");
        $f->addOption("Restore");
        $f->addOption("Delete");
        $f->addOption("View");
        if(isset($data['hideTabs'])) $f->value = $data['hideTabs'];
        $wrapper->add($f);

        $f = $this->wire('modules')->get('InputfieldAsmSelect');
        $f->attr('name+id', 'specifiedTemplates');
        $f->label = __('Specified Templates');
        $f->description = __("If any templates are selected, then only these templates will be affected. If none selected, then all will be affected.");
        $f->setAsmSelectOption('sortable', false);
        // populate with all available templates
        foreach($this->wire('templates') as $t) $f->addOption($t->id,$t->name);
        if(isset($data['specifiedTemplates'])) $f->value = $data['specifiedTemplates'];
        $wrapper->add($f);

        $f = $this->wire('modules')->get('InputfieldCheckbox');
        $f->attr('name+id', 'showNameInContentTab');
        $f->label = __('Show page name in content tab');
        $f->description = __("If settings tabs is hidden, do you want the name field to be visible in the content tab?");
        $f->attr('checked', $data['showNameInContentTab'] == '1' ? 'checked' : '');
        $wrapper->add($f);

        return $wrapper;
    }

}
