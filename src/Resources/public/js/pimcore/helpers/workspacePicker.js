pimcore.registerNS(
  "pimcore.plugin.storeExporterDataObject.helpers.workspace.object"
);
pimcore.plugin.storeExporterDataObject.helpers.workspace.object = Class.create(
  pimcore.plugin.datahub.workspace.abstract,
  {
    type: "object",
    initialize: function (parent, data) {
      this.parent = parent;
      this.workspaces = data;
    },
  }
);
