//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop
#include "Constants.h"

#include "FUpdateCustomerDetails.h"
#include "FMain.h"
#include "AppController.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma link "JvExExtCtrls"
#pragma link "JvExStdCtrls"
#pragma link "JvExtComponent"
#pragma link "JvGroupBox"
#pragma link "JvPanel"
#pragma link "cxContainer"
#pragma link "cxControls"
#pragma link "cxEdit"
#pragma link "cxGraphics"
#pragma link "cxLookAndFeelPainters"
#pragma link "cxLookAndFeels"
#pragma link "cxTextEdit"
#pragma link "cxDBEdit"
#pragma link "cxButtons"
#pragma link "cxButtonEdit"
#pragma link "cxMaskEdit"
#pragma link "dxSkinBlack"
#pragma link "dxSkinBlue"
#pragma link "dxSkinBlueprint"
#pragma link "dxSkinCaramel"
#pragma link "dxSkinCoffee"
#pragma link "dxSkinDarkRoom"
#pragma link "dxSkinDarkSide"
#pragma link "dxSkinDevExpressDarkStyle"
#pragma link "dxSkinDevExpressStyle"
#pragma link "dxSkinFoggy"
#pragma link "dxSkinGlassOceans"
#pragma link "dxSkinHighContrast"
#pragma link "dxSkiniMaginary"
#pragma link "dxSkinLilian"
#pragma link "dxSkinLiquidSky"
#pragma link "dxSkinLondonLiquidSky"
#pragma link "dxSkinMcSkin"
#pragma link "dxSkinMetropolis"
#pragma link "dxSkinMetropolisDark"
#pragma link "dxSkinMoneyTwins"
#pragma link "dxSkinOffice2007Black"
#pragma link "dxSkinOffice2007Blue"
#pragma link "dxSkinOffice2007Green"
#pragma link "dxSkinOffice2007Pink"
#pragma link "dxSkinOffice2007Silver"
#pragma link "dxSkinOffice2010Black"
#pragma link "dxSkinOffice2010Blue"
#pragma link "dxSkinOffice2010Silver"
#pragma link "dxSkinOffice2013DarkGray"
#pragma link "dxSkinOffice2013LightGray"
#pragma link "dxSkinOffice2013White"
#pragma link "dxSkinOffice2016Colorful"
#pragma link "dxSkinOffice2016Dark"
#pragma link "dxSkinPumpkin"
#pragma link "dxSkinsCore"
#pragma link "dxSkinsDefaultPainters"
#pragma link "dxSkinSeven"
#pragma link "dxSkinSevenClassic"
#pragma link "dxSkinSharp"
#pragma link "dxSkinSharpPlus"
#pragma link "dxSkinSilver"
#pragma link "dxSkinSpringTime"
#pragma link "dxSkinStardust"
#pragma link "dxSkinSummer2008"
#pragma link "dxSkinTheAsphaltWorld"
#pragma link "dxSkinTheBezier"
#pragma link "dxSkinValentine"
#pragma link "dxSkinVisualStudio2013Blue"
#pragma link "dxSkinVisualStudio2013Dark"
#pragma link "dxSkinVisualStudio2013Light"
#pragma link "dxSkinVS2010"
#pragma link "dxSkinWhiteprint"
#pragma link "dxSkinXmas2008Blue"
#pragma resource "*.dfm"
TFrmUpdateCustomerDetails *FrmUpdateCustomerDetails;
//---------------------------------------------------------------------------
__fastcall TFrmUpdateCustomerDetails::TFrmUpdateCustomerDetails(TComponent* Owner, long _custId)
	: NewSpecialForm(Owner)
{
 dataset = DatasetCustomer;

 fieldsAssignments.insert(pair<TcxTextEdit*,AnsiString>(editCompany, AnsiString("NAME")));
 fieldsAssignments.insert(pair<TcxTextEdit*,AnsiString>(editAddress1, AnsiString("ADDRESS1")));
 fieldsAssignments.insert(pair<TcxTextEdit*,AnsiString>(editAddress2, AnsiString("ADDRESS2")));
 fieldsAssignments.insert(pair<TcxTextEdit*,AnsiString>(editCity, AnsiString("CITY")));
 fieldsAssignments.insert(pair<TcxTextEdit*,AnsiString>(editPostCode, AnsiString("POSTCODE")));
 fieldsAssignments.insert(pair<TcxTextEdit*,AnsiString>(editCountry, AnsiString("COUNTRY")));
 fieldsAssignments.insert(pair<TcxTextEdit*,AnsiString>(editPhone1, AnsiString("PHONE1")));
 fieldsAssignments.insert(pair<TcxTextEdit*,AnsiString>(editEmail, AnsiString("EMAIL")));
 fieldsAssignments.insert(pair<TcxTextEdit*,AnsiString>(editVatVies, AnsiString("VAT_VIES")));
 fieldsAssignments.insert(pair<TcxTextEdit*,AnsiString>(editTaxOffice, AnsiString("TAXOFFICE")));
 fieldsAssignments.insert(pair<TcxTextEdit*,AnsiString>(editOccupation, AnsiString("OCCUPATION")));

 buttonAssignments.insert(pair<TcxButton*, TcxTextEdit*>(cmdCompanyName, editCompany));
 buttonAssignments.insert(pair<TcxButton*, TcxTextEdit*>(cmdAddress1, editAddress1));
 buttonAssignments.insert(pair<TcxButton*, TcxTextEdit*>(cmdCity, editCity));
 buttonAssignments.insert(pair<TcxButton*, TcxTextEdit*>(cmdPostcode, editPostCode));
 buttonAssignments.insert(pair<TcxButton*, TcxTextEdit*>(cmdCountry, editCountry));
 buttonAssignments.insert(pair<TcxButton*, TcxTextEdit*>(cmdPhone1, editPhone1));
 buttonAssignments.insert(pair<TcxButton*, TcxTextEdit*>(cmdEmail, editEmail));
 buttonAssignments.insert(pair<TcxButton*, TcxTextEdit*>(cmdVatVies, editVatVies));
 buttonAssignments.insert(pair<TcxButton*, TcxTextEdit*>(cmdTaxOffice, editTaxOffice));
 buttonAssignments.insert(pair<TcxButton*, TcxTextEdit*>(cmdOccupation, editOccupation));

 dbEditsAssignments.insert(pair<TcxDBTextEdit*,TcxTextEdit*>(editDbCompany, editCompany));
 dbEditsAssignments.insert(pair<TcxDBTextEdit*,TcxTextEdit*>(editDbAddress1, editAddress1));
 dbEditsAssignments.insert(pair<TcxDBTextEdit*,TcxTextEdit*>(editDbAddress2, editAddress2));
 dbEditsAssignments.insert(pair<TcxDBTextEdit*,TcxTextEdit*>(editDbCity, editCity));
 dbEditsAssignments.insert(pair<TcxDBTextEdit*,TcxTextEdit*>(editDbPostCode, editPostCode));
 dbEditsAssignments.insert(pair<TcxDBTextEdit*,TcxTextEdit*>(editDbCountry, editCountry));
 dbEditsAssignments.insert(pair<TcxDBTextEdit*,TcxTextEdit*>(editDbPhone1, editPhone1));
 dbEditsAssignments.insert(pair<TcxDBTextEdit*,TcxTextEdit*>(editDbEmail, editEmail));
 dbEditsAssignments.insert(pair<TcxDBTextEdit*,TcxTextEdit*>(editDbVatVies, editVatVies));
 dbEditsAssignments.insert(pair<TcxDBTextEdit*,TcxTextEdit*>(editDbTaxOffice, editTaxOffice));
 dbEditsAssignments.insert(pair<TcxDBTextEdit*,TcxTextEdit*>(editDbOccupation, editOccupation));

 dataset->ParamByName("CUST_ID")->AsInteger = _custId;
 dataset->Open();

 dataset->Edit();
}
//---------------------------------------------------------------------------

void TFrmUpdateCustomerDetails::saveAcceptedState()
{
 auto_ptr<TIBQuery> query = getSmartNewQuery();

 query->SQL->Text = "UPDATE OR INSERT INTO CUSTOMER_CS_ACCEPTED(CUST_ID, AFM, VAT_VIES, NAME, ADDRESS1, \
							ADDRESS2, CITY, POSTCODE, PHONE1, OCCUPATION, TAXOFFICE, EMAIL, COUNTRY) \
							VALUES (:CUST_ID, :AFM, :VAT_VIES, :NAME, :ADDRESS1, :ADDRESS2, :CITY, \
							:POSTCODE, :PHONE1, :OCCUPATION, :TAXOFFICE, :EMAIL, :COUNTRY)\
							MATCHING(CUST_ID)";

 query->ParamByName("CUST_ID")->AsInteger = DatasetCustomer->FieldByName("CUST_ID")->AsInteger;
 query->ParamByName("VAT_VIES")->AsWideString = editVatVies->Text;
 query->ParamByName("NAME")->AsWideString = editCompany->Text;
 query->ParamByName("ADDRESS1")->AsWideString = editAddress1->Text;
 query->ParamByName("ADDRESS2")->AsWideString = editAddress2->Text;
 query->ParamByName("CITY")->AsWideString = editCity->Text;
 query->ParamByName("POSTCODE")->AsWideString = editPostCode->Text;
 query->ParamByName("PHONE1")->AsWideString = editPhone1->Text;
 query->ParamByName("OCCUPATION")->AsWideString = editOccupation->Text;
 query->ParamByName("TAXOFFICE")->AsWideString = editTaxOffice->Text;
 query->ParamByName("EMAIL")->AsWideString = editEmail->Text;
 query->ParamByName("COUNTRY")->AsWideString = editCountry->Text;
 query->ExecSQL();
}

void __fastcall TFrmUpdateCustomerDetails::cxButton1Click(TObject *Sender)
{
 if(dataset->Modified)
  dataset->Post();

 saveAcceptedState();

 Close();
}
//---------------------------------------------------------------------------

void __fastcall TFrmUpdateCustomerDetails::cxButton2Click(TObject *Sender)
{
 Close();
}
//---------------------------------------------------------------------------

void __fastcall TFrmUpdateCustomerDetails::cmdDataAsssignClick(TObject *Sender)
{
 TcxTextEdit *textEdit = buttonAssignments[(TcxButton *)Sender];

 AnsiString fieldName = fieldsAssignments[textEdit];
 assignEditToDataset(textEdit, fieldName);
 compareData(textEdit, fieldName);


 if(textEdit->Name == "cmdAddress1")
 {
  cmdDataAsssignClick(editAddress2);
 }
}

void __fastcall TFrmUpdateCustomerDetails::cxButton15Click(TObject *Sender)
{
 for(std::map<TcxButton*, TcxTextEdit*>::iterator it = buttonAssignments.begin(); it != buttonAssignments.end();++it)
 {
  it->first->Click();
 }
}
//---------------------------------------------------------------------------


void TFrmUpdateCustomerDetails::compareData(TcxTextEdit *_editbox, AnsiString _field)
{
  if(_editbox->Text != dataset->FieldByName(_field)->AsString)
  _editbox->Style->TextColor = clRed;
 else
  _editbox->Style->TextColor = clWindowText;
}

void TFrmUpdateCustomerDetails::assignEditToDataset(TcxTextEdit *_editbox, AnsiString _field)
{
 dataset->FieldByName(_field)->AsString = _editbox->Text;
}

void __fastcall TFrmUpdateCustomerDetails::editOnChange(TObject *Sender)
{
 AnsiString fieldName = fieldsAssignments[(TcxTextEdit *)Sender];
 compareData((TcxTextEdit *)Sender, fieldName);
}

void __fastcall TFrmUpdateCustomerDetails::dbEditOnChange(TObject *Sender)
{
 TcxTextEdit *textEdit = dbEditsAssignments[(TcxDBTextEdit*)Sender];

 AnsiString fieldName = fieldsAssignments[textEdit];
 compareData((TcxTextEdit *)textEdit, fieldName);
}

