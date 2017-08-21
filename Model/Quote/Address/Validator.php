<?php 

namespace Yaoli\Paypals\Model\Quote\Address;
use Zend_Validate_Exception;

class Validator extends \Magento\Quote\Model\Quote\Address\Validator
{
	public function isValid($value)
    {
        $messages = [];
        $email = $value->getEmail();
        if (!empty($email) && !\Zend_Validate::is($email, 'EmailAddress')) {
            $messages['invalid_email_format'] = 'Invalid email format';
        }

        $countryId = $value->getCountryId() == 'C2' ? 'CN' : $value->getCountryId();

        if (!empty($countryId)) {
            $country = $this->countryFactory->create();
            $country->load($countryId);
            if (!$country->getId() && $countryId != 'CN') {
                $messages['invalid_country_code'] = 'Invalid country code' . $countryId;
            }
        }

        $this->_addMessages($messages);

        return empty($messages);
    }
}