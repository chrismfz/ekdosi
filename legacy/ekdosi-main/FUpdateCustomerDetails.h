//---------------------------------------------------------------------------

#ifndef FUpdateCustomerDetailsH
#define FUpdateCustomerDetailsH
//---------------------------------------------------------------------------
#include "CNewSpecialForm.h"
#include <System.Classes.hpp>
#include <Vcl.Controls.hpp>
#include <Vcl.StdCtrls.hpp>
#include <Vcl.Forms.hpp>
#include "JvExExtCtrls.hpp"
#include "JvExStdCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvGroupBox.hpp"
#include "JvPanel.hpp"
#include <Vcl.ExtCtrls.hpp>
#include "cxContainer.hpp"
#include "cxControls.hpp"
#include "cxEdit.hpp"
#include "cxGraphics.hpp"
#include "cxLookAndFeelPainters.hpp"
#include "cxLookAndFeels.hpp"
#include "cxTextEdit.hpp"
#include "cxDBEdit.hpp"
#include <Data.DB.hpp>
#include <IBX.IBCustomDataSet.hpp>
#include "cxButtons.hpp"
#include <Vcl.Menus.hpp>
#include <System.ImageList.hpp>
#include <Vcl.ImgList.hpp>
#include "cxButtonEdit.hpp"
#include "cxMaskEdit.hpp"
#include "dxSkinBlack.hpp"
#include "dxSkinBlue.hpp"
#include "dxSkinBlueprint.hpp"
#include "dxSkinCaramel.hpp"
#include "dxSkinCoffee.hpp"
#include "dxSkinDarkRoom.hpp"
#include "dxSkinDarkSide.hpp"
#include "dxSkinDevExpressDarkStyle.hpp"
#include "dxSkinDevExpressStyle.hpp"
#include "dxSkinFoggy.hpp"
#include "dxSkinGlassOceans.hpp"
#include "dxSkinHighContrast.hpp"
#include "dxSkiniMaginary.hpp"
#include "dxSkinLilian.hpp"
#include "dxSkinLiquidSky.hpp"
#include "dxSkinLondonLiquidSky.hpp"
#include "dxSkinMcSkin.hpp"
#include "dxSkinMetropolis.hpp"
#include "dxSkinMetropolisDark.hpp"
#include "dxSkinMoneyTwins.hpp"
#include "dxSkinOffice2007Black.hpp"
#include "dxSkinOffice2007Blue.hpp"
#include "dxSkinOffice2007Green.hpp"
#include "dxSkinOffice2007Pink.hpp"
#include "dxSkinOffice2007Silver.hpp"
#include "dxSkinOffice2010Black.hpp"
#include "dxSkinOffice2010Blue.hpp"
#include "dxSkinOffice2010Silver.hpp"
#include "dxSkinOffice2013DarkGray.hpp"
#include "dxSkinOffice2013LightGray.hpp"
#include "dxSkinOffice2013White.hpp"
#include "dxSkinOffice2016Colorful.hpp"
#include "dxSkinOffice2016Dark.hpp"
#include "dxSkinPumpkin.hpp"
#include "dxSkinsCore.hpp"
#include "dxSkinsDefaultPainters.hpp"
#include "dxSkinSeven.hpp"
#include "dxSkinSevenClassic.hpp"
#include "dxSkinSharp.hpp"
#include "dxSkinSharpPlus.hpp"
#include "dxSkinSilver.hpp"
#include "dxSkinSpringTime.hpp"
#include "dxSkinStardust.hpp"
#include "dxSkinSummer2008.hpp"
#include "dxSkinTheAsphaltWorld.hpp"
#include "dxSkinTheBezier.hpp"
#include "dxSkinValentine.hpp"
#include "dxSkinVisualStudio2013Blue.hpp"
#include "dxSkinVisualStudio2013Dark.hpp"
#include "dxSkinVisualStudio2013Light.hpp"
#include "dxSkinVS2010.hpp"
#include "dxSkinWhiteprint.hpp"
#include "dxSkinXmas2008Blue.hpp"
#include <map>
//---------------------------------------------------------------------------
class TFrmUpdateCustomerDetails : public NewSpecialForm
{
__published:	// IDE-managed Components
	TJvGroupBox *JvGroupBox1;
	TJvGroupBox *JvGroupBox2;
	TJvPanel *JvPanel1;
	TJvPanel *JvPanel2;
	TLabel *Label3;
	TLabel *Label6;
	TLabel *Label7;
	TcxTextEdit *editCompany;
	TLabel *Label5;
	TLabel *Label8;
	TcxTextEdit *editAddress1;
	TcxTextEdit *editAddress2;
	TcxTextEdit *editCity;
	TcxTextEdit *editPostCode;
	TcxTextEdit *editTaxOffice;
	TDataSource *DSCustomers;
	TIBDataSet *DatasetCustomer;
	TLabel *Label2;
	TcxTextEdit *editOccupation;
	TLabel *Label9;
	TLabel *Label10;
	TcxDBTextEdit *editDbCompany;
	TcxDBTextEdit *editDbAddress1;
	TcxDBTextEdit *editDbAddress2;
	TcxDBTextEdit *editDbPostCode;
	TcxDBTextEdit *editDbCity;
	TLabel *Label11;
	TLabel *Label12;
	TcxDBTextEdit *editDbTaxOffice;
	TcxDBTextEdit *editDbOccupation;
	TLabel *Label14;
	TLabel *Label15;
	TIntegerField *DatasetCustomerCUST_ID;
	TIntegerField *DatasetCustomerALT_CUSTID;
	TIBStringField *DatasetCustomerAFM;
	TIBStringField *DatasetCustomerNAME;
	TIBStringField *DatasetCustomerADDRESS1;
	TIBStringField *DatasetCustomerADDRESS2;
	TIBStringField *DatasetCustomerCITY;
	TIBStringField *DatasetCustomerPOSTCODE;
	TIBStringField *DatasetCustomerPHONE1;
	TIBStringField *DatasetCustomerPHONE2;
	TIBStringField *DatasetCustomerFAX;
	TIBStringField *DatasetCustomerOCCUPATION;
	TIBStringField *DatasetCustomerTAXOFFICE;
	TWideMemoField *DatasetCustomerDETAILS;
	TIBBCDField *DatasetCustomerDISCOUNT;
	TIBStringField *DatasetCustomerSECONDARY_EMAIL;
	TIBStringField *DatasetCustomerEMAIL;
	TIntegerField *DatasetCustomerORDER;
	TIBStringField *DatasetCustomerCOUNTRY;
	TIntegerField *DatasetCustomerPAYMETH_ID;
	TIBStringField *DatasetCustomerVAT_VIES;
	TcxTextEdit *editCountry;
	TLabel *Label16;
	TcxTextEdit *editPhone1;
	TLabel *Label17;
	TcxTextEdit *editEmail;
	TLabel *Label18;
	TcxDBTextEdit *editDbCountry;
	TLabel *Label19;
	TcxDBTextEdit *editDbPhone1;
	TLabel *Label20;
	TcxDBTextEdit *editDbEmail;
	TLabel *Label21;
	TcxButton *cxButton1;
	TLabel *Label1;
	TcxTextEdit *editVatVies;
	TcxDBTextEdit *editDbVatVies;
	TLabel *Label22;
	TcxButton *cxButton2;
	TImageList *ImageList1;
	TcxButton *cmdCompanyName;
	TcxButton *cmdAddress1;
	TcxButton *cmdCity;
	TcxButton *cmdPostcode;
	TcxButton *cmdCountry;
	TcxButton *cmdPhone1;
	TcxButton *cmdEmail;
	TcxButton *cmdVatVies;
	TcxButton *cmdTaxOffice;
	TcxButton *cmdOccupation;
	TcxButton *cxButton15;
	void __fastcall cxButton1Click(TObject *Sender);
	void __fastcall cxButton2Click(TObject *Sender);
	void __fastcall cxButton15Click(TObject *Sender);
	void __fastcall cmdDataAsssignClick(TObject *Sender);
	void __fastcall editOnChange(TObject *Sender);
	void __fastcall dbEditOnChange(TObject *Sender);
private:	// User declarations
	std::map<TcxTextEdit*, AnsiString> fieldsAssignments;
	std::map<TcxButton*, TcxTextEdit*> buttonAssignments;
    std::map<TcxDBTextEdit*,TcxTextEdit*> dbEditsAssignments;

	void compareData(TcxTextEdit *_editbox, AnsiString _field);
	void assignEditToDataset(TcxTextEdit *_editbox, AnsiString _field);
	void saveAcceptedState();
public:		// User declarations
	__fastcall TFrmUpdateCustomerDetails(TComponent* Owner, long _custId);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmUpdateCustomerDetails *FrmUpdateCustomerDetails;
//---------------------------------------------------------------------------
#endif
