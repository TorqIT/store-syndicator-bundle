# store-syndicator-bundle

### Setting up an export:

1. create a new "TorqStoreExporterShopifyCredentials" object with your shopify store's credentials.
2. create a new "Store Exporter" object in the pimcore datahub.
3. under the API Access tab drag in your credentials object created in step 1
4. under the Choose Products tab choose the dataobject class you will be exporting and one or multiple folders or objects to export.
5. finally in the Map Attributes tab fill the table provided.


    - the "local field" tab refers to the field on your dataobject class to export
    - "field type" column is where you choose what you are exporting data to like product or variant
    - "remote field" column is where you choose what field to export the data to in whatever you chose for "field type"
    - select one (or none) row that is mapped to a vairnat to "map on" telling the store exporter that this column will be unique on all exported variants

### Running an export:

the command to run is `bin/console torq:push-to-shopify "your-store-name"`
if you expect this export to take longer than a few minutes (or more than a few thousand variants) please wrap the command like follows:
`nohup bin/console torq:push-to-shopify "your-store-name" &`
