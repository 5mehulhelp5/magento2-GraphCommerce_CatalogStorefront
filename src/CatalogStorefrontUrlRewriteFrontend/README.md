# GraphCommerce_CatalogStorefrontUrlRewriteFrontend

Every url rewrite lookup of a rendered page from the url rewrite documents of
GraphCommerce_CatalogStorefrontUrlRewrite: the routing of the request path, the links to products
and categories, the bulk lookup of a product collection, the store switcher. It acts in a store
view that serves its listings or its product pages from documents (`serve_plp`, `serve_pdp`, or
the `X-Catalog-Storefront` header of GraphCommerce_CatalogStorefrontProductFrontend). Frontend
area only; GraphQL reads the table.

- [`Plugin/UrlFinderFromDocuments`](Plugin/UrlFinderFromDocuments.php) answers
  `findOneByData()` and `findAllByData()` of the url rewrite table storage, and so of the catalog
  and dynamic storages that extend it. Callers ask the storages directly as well as through the
  composite url finder (the product collection asks the catalog storage for the urls of its
  products), and the composite merges the answers as it always does. A read that fails stops the
  page.
- [`Model/Read/UrlFinder`](Model/Read/UrlFinder.php) takes the steps of the storage asked on the
  documents: the table storage, the catalog storage with the product category relation or, with
  product rewrites per category off (`catalog/seo/generate_category_product_rewrites`), the
  dynamic storage that builds the url of a product in a category. It reads every row those steps
  can select in one search per store view, shared by the storages of one lookup, and compares as
  the table does: string columns through the collation, integer columns as numbers. A lookup by
  request path takes the rows in the order of the request path index, any other in primary key
  order. Whether a product shows in a category comes from the `categoryIds` of its product
  document, the category product index of the store view. A lookup is read once per request.

A module that reads the `url_rewrite` table with its own SQL still reads the table; the Snowdog
menu's read is served by GraphCommerce_CatalogStorefrontSnowdogMenu.

Parity: `dev/parity/route-pages.txt` with the fixture `dev/parity/fixtures/url-rewrites.php`.
