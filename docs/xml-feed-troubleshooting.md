# XML feed column troubleshooting

XML previews now detect repeated product/item records inside collection wrappers and flatten their nested values into usable dotted columns, rather than treating wrapper metadata such as `Href` or `Relations` as products. XML attributes appear as `@attribute` on the selected item or as `path.@attribute` when nested.

The feed editor has an **XML item XPath** field. Enter the repeated product node path and click **Preview columns** again. Common selectors include `//ProductDto`, `//Product`, `//product`, `/Products/Product`, and `//entry`. If the feed uses XML namespaces, use `//ProductDto` (the parser retries by local element name) or the explicit XPath `//*[local-name()="ProductDto"]`. The chosen XPath is saved with each feed and reused for imports and previews.

The URL alone cannot establish the exact supplier schema. If the preview remains incorrect, inspect a small redacted XML sample and set the XPath to the repeated element that contains each product's fields.
