# newspage-sorting
This extension adds automatic sorting of news pages inside a "newspage" module to the backend.

In order for this to work, simply select the "News" module type of folder in the backend and create news inside of it.

By default, news pages are sorted into new folders by year, month and day. 

## Settings

The extension settings allow changing the sorting depth by disabling folders for each day, or month.

### Further Customisation

For further customisation, you can override the hook in your project.  
The hook is registered with a fixed array key `tx_b13_newspage_sorting` so you can easily extend the hook and replace
it with your own version:

```php
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['tx_newspage_sorting'] =
    YourVendor\YourExtension\Hooks\SortNewsOverride::class;
```

This class contains the whole logic for the creation and sorting of the storage folders. 

The most important function which you might want to override within this hook is `getCustomisableFieldsForFolder`,
which allows changing the title and setting values for other fields, if required.

```php
    protected function getCustomisableFieldsForFolder(\DateTime $date, FolderType $type): array
    {
        return [
            'title' => $date->format($type->value),
        ];
    }
```
