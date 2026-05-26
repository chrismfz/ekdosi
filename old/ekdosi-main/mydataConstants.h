//---------------------------------------------------------------------------

#ifndef mydataConstantsH
#define mydataConstantsH
//---------------------------------------------------------------------------

#define APY_TEMPLATE_XML "<?xml version=\"1.0\" encoding=\"UTF-8\"?>  \
				<InvoicesDoc xmlns=\"http://www.aade.gr/myDATA/invoice/v1.0\" \
				xmlns:schemaLocation=\"http://www.aade.gr/myDATA/invoice/v1.0/InvoicesDoc-v0.6.xsd\" \
					xmlns:icls=\"https://www.aade.gr/myDATA/incomeClassificaton/v1.0\" \
					xmlns:ecls=\"https://www.aade.gr/myDATA/expensesClassificaton/v1.0\">\
					<invoice>\
					<issuer>\
					<vatNumber>%COMPANY_VATNO%</vatNumber>\
					<country>GR</country>\
					<branch>0</branch>\
					</issuer>\
					<invoiceHeader>\
					<series>%SEIRA%</series>\
					<aa>%ARITHMOS%</aa>\
					<issueDate>%DATE%</issueDate>\
					<invoiceType>%MYDATA_TYPE%</invoiceType>\
					<currency>EUR</currency>\
					</invoiceHeader>\
					<paymentMethods>\
					<paymentMethodDetails>\
					<type>3</type>\
					<amount>%TOTAL_WVAT%</amount>\
					<paymentMethodInfo>%PAYMENT_METHOD%</paymentMethodInfo>\
					</paymentMethodDetails>\
					</paymentMethods>\
					%INVLINES%\
					<invoiceSummary>\
					<totalNetValue>%TOTAL_WOUTVAT%</totalNetValue>\
					<totalVatAmount>%TOTAL_VAT%</totalVatAmount>\
					<totalWithheldAmount>%TOTAL_WITHHELD_AMOUNT%</totalWithheldAmount>\
					<totalFeesAmount>0.00</totalFeesAmount>\
					<totalStampDutyAmount>0.00</totalStampDutyAmount>\
					<totalOtherTaxesAmount>0.00</totalOtherTaxesAmount>\
					<totalDeductionsAmount>0.00</totalDeductionsAmount>\
					<totalGrossValue>%TOTAL_GROSS_AMOUNT%</totalGrossValue>\
					<incomeClassification>\
					<icls:classificationType>%INCOME_CLASS%</icls:classificationType>\
					<icls:classificationCategory>%INCOME_CLASS_CATEGORY%</icls:classificationCategory>\
					<icls:amount>%TOTAL_WOUTVAT%</icls:amount>\
					</incomeClassification>\
					</invoiceSummary>\
					</invoice>\
					</InvoicesDoc>"

#define INVOICE_TEMPLATE_XML "<?xml version=\"1.0\" encoding=\"UTF-8\"?>  \
				<InvoicesDoc xmlns=\"http://www.aade.gr/myDATA/invoice/v1.0\" \
				xmlns:schemaLocation=\"http://www.aade.gr/myDATA/invoice/v1.0/InvoicesDoc-v0.6.xsd\" \
					xmlns:icls=\"https://www.aade.gr/myDATA/incomeClassificaton/v1.0\" \
					xmlns:ecls=\"https://www.aade.gr/myDATA/expensesClassificaton/v1.0\">\
					<invoice>\
					<issuer>\
					<vatNumber>%COMPANY_VATNO%</vatNumber>\
					<country>GR</country>\
					<branch>0</branch>\
					</issuer>\
					<counterpart>\
					<vatNumber>%CUST_AFM%</vatNumber>\
					<country>GR</country>\
					<branch>0</branch>\
					<address>\
					<postalCode>%POSTAL_CODE%</postalCode>\
					<city>%CITY%</city>\
					</address>\
					</counterpart>\
					<invoiceHeader>\
					<series>%SEIRA%</series>\
					<aa>%ARITHMOS%</aa>\
					<issueDate>%DATE%</issueDate>\
					<invoiceType>%MYDATA_TYPE%</invoiceType>\
					<currency>EUR</currency>\
					</invoiceHeader>\
					<paymentMethods>\
					<paymentMethodDetails>\
					<type>3</type>\
					<amount>%TOTAL_WVAT%</amount>\
					<paymentMethodInfo>%PAYMENT_METHOD%</paymentMethodInfo>\
					</paymentMethodDetails>\
					</paymentMethods>\
					%INVLINES%\
					<invoiceSummary>\
					<totalNetValue>%TOTAL_WOUTVAT%</totalNetValue>\
					<totalVatAmount>%TOTAL_VAT%</totalVatAmount>\
					<totalWithheldAmount>%TOTAL_WITHHELD_AMOUNT%</totalWithheldAmount>\
					<totalFeesAmount>0.00</totalFeesAmount>\
					<totalStampDutyAmount>0.00</totalStampDutyAmount>\
					<totalOtherTaxesAmount>0.00</totalOtherTaxesAmount>\
					<totalDeductionsAmount>0.00</totalDeductionsAmount>\
					<totalGrossValue>%TOTAL_GROSS_AMOUNT%</totalGrossValue>\
					<incomeClassification>\
					<icls:classificationType>%INCOME_CLASS%</icls:classificationType>\
					<icls:classificationCategory>%INCOME_CLASS_CATEGORY%</icls:classificationCategory>\
					<icls:amount>%TOTAL_WOUTVAT%</icls:amount>\
					</incomeClassification>\
					</invoiceSummary>\
					</invoice>\
					</InvoicesDoc>"

#define INV_LINE_TEMPLATE_XML "<invoiceDetails> \
					<lineNumber>%INDEX%</lineNumber> \
					<netValue>%PRICE_WOUTVAT%</netValue> \
					<vatCategory>1</vatCategory> \
					<vatAmount>%VAT_AMOUNT%</vatAmount> \
					<withheldAmount>%WITHHELD_AMOUNT%</withheldAmount> \
					<withheldPercentCategory>3</withheldPercentCategory> \
					<incomeClassification> \
					<icls:classificationType>%INCOME_CLASS%</icls:classificationType> \
					<icls:classificationCategory>%INCOME_CLASS_CATEGORY%</icls:classificationCategory> \
					<icls:amount>%PRICE_WOUTVAT%</icls:amount> \
					</incomeClassification> \
					</invoiceDetails>"

#endif
