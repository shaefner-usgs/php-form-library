<?php

/**
 * Create HTML <input>
 *
 * @param $params {Array}
 *     html input attributes; supported properties are:
 *
 *       checked {Boolean}
 *       disabled {Boolean}
 *       id {String} - REQUIRED for all radio/checkbox inputs
 *       inputmode {String}
 *       max {Integer}
 *       maxlength {Integer}
 *       min {Integer}
 *       minlength {Integer}
 *       name {String} - REQUIRED for all inputs; for radio/checkbox inputs, use the same 'name' for all inputs in group
 *       pattern {RegExp}
 *       placeholder {String}
 *       readonly {Boolean}
 *       required {Boolean}
 *       type {String} default is 'text'
 *       value {String} - REQUIRED for all radio/checkbox inputs
 *
 *     other properties:
 *
 *       class {String}
 *       description {String} - explanatory text displayed next to form control
 *       flatpickrOptions {Array} - flatpickr datetime picker lib
 *       label {String} - label element for control
 *       message {String} - instructions displayed for invalid form control
 */
class Input {
  private $_defaults = [
      'checked' => false,
      'class' => '',
      'description' => '',
      'disabled' => false,
      'flatpickrOptions' => [],
      'id' => '',
      'inputmode' => '',
      'label' => '',
      'max' => null,
      'maxlength' => null,
      'message' => 'Please provide a valid {{label}}',
      'min' => null,
      'minlength' => null,
      'name' => '',
      'pattern' => '',
      'placeholder' => '',
      'readonly' => false,
      'required' => false,
      'type' => 'text',
      'value' => ''
    ],
    $_flatpickrIndex,
    $_instantiatedValue,
    $_isCheckboxOrRadio = false,
    $_jsonOptions = [],
    $_jsonPlaceholders = [],
    $_jsonValues = [],
    $_submittedValue;

  private static $_numDatetimeFields = 0;
  public $isValid = true;

  public function __construct (Array $params=[]) {
    $this->_setDefaults($params);

    // Merge defaults with user-supplied params and set as class properties
    $options = array_merge($this->_defaults, $params);
    foreach ($options as $key => $value) {
      // Strip off '[]' from name values; added programmatically to checkbox inputs
      if ($key === 'name' && preg_match('/\[\]$/', $value)) {
        $value = preg_replace('/(\w+)\[\]$/', '$1', $value);
      }
      // Only set props that are defined in $_defaults
      if (array_key_exists($key, $this->_defaults)) {
        $this->$key = $value;
      }
    }

    if ($this->type === 'datetime') {
      self::$_numDatetimeFields ++;
      $this->_flatpickrIndex = self::$_numDatetimeFields - 1; // want 0-based index
    }

    $this->_checkParams($params);
    $this->_setValue();
  }

  /**
   * Check for missing required params; set id, label params if not already set
   */
  private function _checkParams ($params) {
    if (!$this->name) {
      print '<p class="error">ERROR: the <em>name</em> attribute is <strong>required</strong> for all input elements</p>';
    }
    if ($this->type === 'checkbox' || $this->type === 'radio') {
      $this->_isCheckboxOrRadio = true;
      if (!$this->id) {
        printf ('<p class="error">ERROR: the <em>id</em> attribute is <strong>required</strong> for all radio/checkbox inputs (%s)</p>',
          $this->name
        );
      }
      if (!$this->value) {
        printf ('<p class="error">ERROR: the <em>value</em> attribute is <strong>required</strong> for all radio/checkbox inputs (%s)</p>',
          $this->name
        );
      }
    }

    // Alert user to set message/description when adding radio/checkbox group to form
    if ($this->_isCheckboxOrRadio) {
      if (isSet($params['description']) || isSet($params['message'])) {
        printf ('<p class="error">ERROR: the <em>description</em> and <em>message</em> properties should be set when adding a radio/checkbox group of controls to the form, using Form&rsquo;s addGroup() method (%s)',
          $this->name
        );
      }
    }

    // Set id and label if not set during instantiation
    if (!$this->id) {
      $this->id = $this->name;
    }
    if (!$this->label) {
      $this->label = ucfirst($this->name);
      if ($this->_isCheckboxOrRadio) {
        $this->label = ucfirst($this->value);
      }
    }
  }

  /**
   * php's json_encode with support for javascript expressions passed as strings
   *
   * @param $opts {Array}
   *
   * @return $json {String}
   */
  private function _encode ($opts) {
    $this->_jsonOptions = $opts;

    foreach ($this->_jsonOptions as $key => $value) {
      $this->_replaceExpressions($key, $value);
    }

    $json = json_encode($this->_jsonOptions);
    $json = str_replace($this->_jsonPlaceholders, $this->_jsonValues, $json);

    return $json;
  }

  /**
   * Get optional html attributes for control
   *
   * @param $tabindex {Integer}
   *
   * @return $attrs {String}
   */
  private function _getAttrs ($tabindex) {
    $attrs = '';

    if ($this->disabled) {
      $attrs .= ' disabled="disabled"';
    }
    if ($this->inputmode) {
      $attrs .= sprintf(' inputmode="%s"', $this->inputmode);
    }
    if ($this->pattern) {
      $attrs .= sprintf(' pattern="%s"', $this->pattern);
    }
    if ($this->placeholder) {
      $attrs .= sprintf(' placeholder="%s"', $this->placeholder);
    }
    if ($this->required) {
      $attrs .= ' required="required"';
    }
    if ($this->readonly) {
      $attrs .= ' readonly="readonly"';
    }
    if ($tabindex) {
      $attrs .= sprintf(' tabindex="%d"', $tabindex);
    }

    if ($this->type === 'address') {
      $attrs .= ' data-type="address"';
    }
    if ($this->type === 'datetime') {
      $attrs .= ' data-type="datetime"';
    }
    if ($this->type === 'number') {
      $attrs .= sprintf(' max="%s" min="%s"',
        $this->max,
        $this->min
      );
    }
    if ($this->type === 'text' && $this->maxlength) {
      $attrs .= sprintf(' maxlength="%s"', $this->maxlength);
    }
    if ($this->type === 'text' && $this->minlength) {
      $attrs .= sprintf(' minlength="%s"', $this->minlength);
    }

    if ($this->_isCheckboxOrRadio) {
      if ($this->isChecked()) {
        $attrs .= ' checked="checked"';
      }
    }

    return $attrs;
  }

  /**
   * Get relevant css classes for control
   *
   * @return $cssClasses {Array}
   */
  private function _getCssClasses () {
    $cssClasses = ['control', $this->type];
    if ($this->class) {
      $cssClasses[] = $this->class;
    }
    // Add classes for pretty checkbox library
    if ($this->_isCheckboxOrRadio) {
      array_push($cssClasses, 'pretty', 'p-default', 'p-pulse');
      if ($this->type === 'radio') {
        $cssClasses[] = 'p-round';
      }
    }
    // Invalid radio / checkbox controls handled in Form class (class attached to parent)
    if (!$this->isValid && !$this->_isCheckboxOrRadio) {
      $cssClasses[] = 'invalid';
    }

    return $cssClasses;
  }

  /**
   * Set defaults that depend on type
   *
   * @param $params {Array}
   */
  private function _setDefaults ($params) {
    if (isSet($params['type'])) {
      if ($params['type'] === 'email') {
        $this->_defaults['pattern'] = '[^@]+@[^@]+\.[^@]+';
      }
      if ($params['type'] === 'number') {
        $this->_defaults['pattern'] = '^[0-9.-]+$';
      }
      if ($params['type'] === 'url') {
        $this->_defaults['pattern'] = '^(https?|ftp)://[^\s/$.?#].[^\s]*$';
      }
      if ($params['type'] === 'checkbox') {
        $this->_defaults['message'] = 'Please select one or more options';
      }
      if ($params['type'] === 'radio') {
        $this->_defaults['message'] = 'Please select an option';
      }
      if ($params['type'] === 'url') {
        $this->_defaults['description'] = 'Include &ldquo;http://&rdquo; or &ldquo;https://&rdquo;';
      }
    }
  }

  /**
   * Replace javascript expressions with a placeholder, and store placeholder
   *   keys/original values for later substitution after using json_encode()
   *
   * @param $key {String}
   * @param $value {Mixed}
   */
  private function _replaceExpressions ($key, $value) {
    $patterns = [
      '/new\s+Date\([^)]*\)/s', // dates
      '/function\s*\(.*\)\s*\{.*\}/s', // functions
      '/document\.querySelector\s*\(.*\)/' // elements
    ];

    // Flatten arrays into a string (including the array brackets)
    $jsonValue = $value;
    if (is_array($value)) {
      $value = implode(',', $value);
      $jsonValue = "[$value]";
    }

    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $value)) {
        $placeholder = '{{' . $key . '}}';
        $this->_jsonOptions[$key] = $placeholder;
        $this->_jsonPlaceholders[] = '"' . $placeholder . '"';
        $this->_jsonValues[] = $jsonValue;
      }
    }
  }

  /**
   * Cache instantiated/submitted values and set value prop depending on state
   */
  private function _setValue () {
    $this->_instantiatedValue = $this->value;
    if (isSet($_POST['submitbutton'])) {
      if ($this->type === 'address') {
        // 'Fish' for submitted address input (random string appended to name)
        $pattern = '/^' . $this->name . '\d{5}$/';
        foreach($_POST as $key => $value) {
          if (preg_match($pattern, $key)) { // found match
            $this->_submittedValue = safeParam($key);
          }
        }
      } else {
        $this->_submittedValue = safeParam($this->name);
      }
      $this->value = $this->_submittedValue; // set to user-supplied value when posting
    }
  }

  /**
   * Get HTML for element
   *
   * @param $tabindex {Integer}
   *
   * @return $html {String}
   */
  public function getHtml ($tabindex=NULL) {
    $attrs = $this->_getAttrs($tabindex);
    $cssClasses = $this->_getCssClasses();

    $maxLength = intval($this->maxlength);
    $minLength = intval($this->minlength);
    $name = $this->name;
    $type = $this->type;

    // Create note about req'd number of chars. if applicable
    $msgLength = '';
    if ($minLength && $maxLength) {
      $msgLength = "$minLength&ndash;$maxLength characters";
    } else if ($minLength) { // minlength only set
      $msgLength = "at least $minLength characters";
    } else if ($maxLength){ // maxlength only set
      $msgLength = "no more than $maxLength characters";
    }

    // If no custom description was set, default to showing min/max-length requirements
    $description = $this->description;
    if (!$description && $msgLength) {
      $description = $msgLength;
    }

    $label = sprintf('<label for="%s">%s</label>',
      $this->id,
      $this->label
    );

    // Substitute values for mustache placeholders
    $message = preg_replace('/{{(label|name)}}/', strtoupper($this->label), $this->message);

    // If no custom message was set, append min/max-length requirements
    if ($this->message === $this->_defaults['message'] && $msgLength) {
      $message .= " ($msgLength)";
    }

    if ($type === 'checkbox') {
      $name .= '[]'; // set name to type Array for checkbox values
    } else if ($type === 'address') {
      $randomNumber = sprintf("%05d", mt_rand(1, 99999));
      $type = 'search'; // set type to 'search' for MapQuest PlaceSearch.js
      $name .= $randomNumber; // add random number to disable browser's autocomplete
    } else if ($type === 'datetime') {
      $type = 'text'; // set type to text for flatpickr
    }

    if ($this->_isCheckboxOrRadio) {
      $info = ''; // message / description set via Form's addGroup() method
      // Wrap label in div elem for pretty checkbox library
      $label = sprintf('<div class="state p-primary-o">%s</div>', $label);
      $value = $this->_instantiatedValue; // always use instantiated value for checkbox/radio
    } else {
      $info = sprintf('<p class="description" data-message="%s">%s</p>',
        $message,
        $description
      );
      $value = $this->value; // instantiated or user-supplied value depending on form state
    }

    $input = sprintf('<input id="%s" name="%s" type="%s" value="%s"%s />',
      $this->id,
      $name,
      $type,
      $value,
      $attrs
    );

    if ($this->type === 'hidden') {
      $html = $input; // only include input tag for hidden fields
    } else {
      $html = sprintf('<div class="%s">%s%s%s</div>',
        implode(' ', $cssClasses),
        $info,
        $input,
        $label
      );

      // set inline .js var with flatpickr options
      if ($this->type === 'datetime') {
        $varInit = '';
        if ($this->_flatpickrIndex === 0) {
          $varInit = 'var flatpickrOptions = [];';
        }
        $html .= sprintf('<script>
            %s
            function initFlatpickrOptions%d() {
              flatpickrOptions[%d] = %s;
            }
          </script>',
          $varInit,
          $this->_flatpickrIndex,
          $this->_flatpickrIndex,
          $this->_encode($this->flatpickrOptions)
        );
      }
    }

    return $html;
  }

  /**
   * Assess if radio / checkbox is / should be checked
   *
   * @return $checked {Boolean}
   */
  public function isChecked () {
    $checked = false;

    if (isSet($_POST['submitbutton'])) {
      if ($this->type === 'checkbox') {
        $submittedValues = preg_split('/,\s*/', $this->_submittedValue);
        foreach ($submittedValues as $value) {
          if ($value === $this->_instantiatedValue) {
            $checked = true;
          }
        }
      } else if ($this->type === 'radio') {
        if ($this->_submittedValue === $this->_instantiatedValue) {
          $checked = true;
        }
      }
    } else if ($this->checked) { // set to initial state
      $checked = true;
    }

    return $checked;
  }
}
