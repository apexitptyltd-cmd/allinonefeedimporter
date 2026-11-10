## XML feed column troubleshooting

XML feeds often wrap products in one or more container nodes (including namespaced Atom/OData responses). The importer now looks for repeated product-like elements and descends through wrapper nodes instead of treating each root child as a separate product row. XML field names are flattened into paths such as `ProductDto.Name`, `ProductDto.Sku`, or `@id`.

If automatic detection still selects the wrong nodes, choose **XML item XPath** in the feed form, enter the path for the repeated product/item element, then click **Preview columns** again. Common examples are `//Product`, `//ProductDto`, `//product`, `/Products/Product`, and `//entry`. For namespace-specific XML, a local-name XPath such as `//*[local-name()="ProductDto"]` can be used. The preview should show product fields (not wrapper fields such as `Href` or `Relations`) before mapping.

The `Products` URL in the screenshot appears to return an XML service/collection wrapper, not a flat product list. Try the XPath control with `//ProductDto` first; if that does not match, inspect the feed XML and select its actual repeated product element. The importer does not infer a supplier's exact schema from the URL alone.
