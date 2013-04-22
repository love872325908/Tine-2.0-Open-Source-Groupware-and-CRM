/*
 * Tine 2.0
 * 
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Cornelius Weiss <c.weiss@metaways.de>
 * @copyright   Copyright (c) 2012-2013 Metaways Infosystems GmbH (http://www.metaways.de)
 */
Ext.ns('Tine.Tinebase');

/**
 * central config
 */
Tine.Tinebase.configManager = function(){
    return {
        get: function(name, appName) {
            var app = Tine.Tinebase.hasOwnProperty('appMgr') ? Tine.Tinebase.appMgr.get(appName) : null;
            if (app) {
                registry = app ? app.getRegistry() : Tine.Tinebase.registry,
                config = registry ? registry.get('config') : false,
                struct = config ? config[name] : false,
                def = struct ? struct.definition : false,
                value = struct ? struct.value : null;
            } else {
                var value = null;
            }
            return value;
        }
    }
}();