# Pimcore Store Syndicator Bundle

Exports Pimcore objects into e-commerce storefronts such as Shopify.

Currently, this bundle is designed to work only with a Shopify store, but could be expanded in the future to support other e-commerce frameworks.

### Installation:

1. Run `composer require torqit/store-syndicator-bundle` in your project's terminal.
2. enable the bundle in either Kernel or bundles.php
3. Then run `bin/console pimcore:bundle:install StoreSyndicatorBundle`

### Setting up an export:

1. Create a new "TorqStoreExporterShopifyCredentials" Data Object with your Shopify store's credentials.
2. Create a new "Store Exporter" item in the Pimcore Datahub.
3. In your created Store Exporter, under the API Access tab, drag in your credentials object created in step 1.
4. Under the Choose Products tab
   1. Under BaseClass choose the Data Object class you will be exporting
   2. Under "Product Query Qhere Clause" you can write the SQL where clause for the pimcore object listing for the export.
      Some examples include:
      - `o_path LIKE "your products folder path"` for path based exports
      - `fieldName = value` for object field based export
5. In the Map Attributes tab, fill in the table with the appropriate details:
   - The "Local Field" column refers to the field on your Pimcore Data Object class to export
   - The "Field Type" column refers to the store Field Type
     - Base Product: information on the main product. Including Title, Description, vendor, and status.
     - Images: files that will be uploaded and found in the product media section.
     - Metafields: custom created shopify fields defined on the main product
     - Variant Metafields: custom created shopify fields defined on the variants
     - Base Variant: similar to Base Product, fields found on all shopify variants. Fields include pricing, inventory, and shipping information.
   - The "Remote Field" column refers to the field name in your store. Only available options, limited by the type of the local field and the selected field type , are listed.
   - if there is a pimcore variant attribute that should be used

### Running an export:

In a terminal, run the command `bin/console torq:push-to-shopify "your-store-name"`. If you expect this export to take longer than a few minutes (or you are exporting more than a few thousand variants), wrap the command with `nohup` as follows to ensure the process continues running if your terminal session ends unexpectedly: `nohup bin/console torq:push-to-shopify "your-store-name" &`.

If you would like the command to run on set intervals you can add a line to your projects crontab file.
You can also use [Crontab Guru](https://crontab.guru/) for help with creating your cron configuration.

### Viewing Results:

The tab labeled "Execution", in a Store Exporter datahub config, contains a table of logs. This table will show any files or responses returned from API calls made while exporting your products, as well as any errors that shopify returns.
