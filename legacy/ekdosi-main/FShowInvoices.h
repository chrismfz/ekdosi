//---------------------------------------------------------------------------

#ifndef FShowInvoicesH
#define FShowInvoicesH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "JvDBDotNetControls.hpp"
#include "JvDBGrid.hpp"
#include "JvDBUltimGrid.hpp"
#include "JvExComCtrls.hpp"
#include "JvExControls.hpp"
#include "JvExDBGrids.hpp"
#include "JvExExtCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvLabel.hpp"
#include "JvLookOut.hpp"
#include "JvPanel.hpp"
#include "JvRollOut.hpp"
#include "JvToolBar.hpp"
#include <ComCtrls.hpp>
#include <DB.hpp>
#include <DBCtrls.hpp>
#include <DBGrids.hpp>
#include <ExtCtrls.hpp>
#include <Grids.hpp>
#include <ImgList.hpp>
#include <Mask.hpp>
#include <ToolWin.hpp>
#include "JvComCtrls.hpp"
#include "JvDBControls.hpp"
#include "JvDBLookup.hpp"
#include "JvDotNetControls.hpp"
#include "JvEdit.hpp"
#include "JvExMask.hpp"
#include "JvExStdCtrls.hpp"
#include "JvToolEdit.hpp"
#include "JvDateTimePicker.hpp"
#include "JvDBDateTimePicker.hpp"
#include "cxContainer.hpp"
#include "cxControls.hpp"
#include "cxDBEdit.hpp"
#include "cxEdit.hpp"
#include "cxGraphics.hpp"
#include "cxLookAndFeelPainters.hpp"
#include "cxLookAndFeels.hpp"
#include "cxTextEdit.hpp"
#include <IBX.IBCustomDataSet.hpp>
#include <System.ImageList.hpp>
#include <IBX.IBQuery.hpp>
#include "cxClasses.hpp"
#include "cxCustomData.hpp"
#include "cxData.hpp"
#include "cxDataStorage.hpp"
#include "cxDBData.hpp"
#include "cxFilter.hpp"
#include "cxGrid.hpp"
#include "cxGridCustomTableView.hpp"
#include "cxGridCustomView.hpp"
#include "cxGridDBTableView.hpp"
#include "cxGridLevel.hpp"
#include "cxGridTableView.hpp"
#include "cxNavigator.hpp"
#include "cxStyles.hpp"
#include "cxCalendar.hpp"
#include "cxDropDownEdit.hpp"
#include "cxMaskEdit.hpp"
#include "cxDataControllerConditionalFormattingRulesManagerDialog.hpp"
#include <System.Net.HttpClient.hpp>
#include <System.Net.HttpClientComponent.hpp>
#include <System.Net.URLClient.hpp>
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

#include <vector>
#include "CEditBox.h"

#include "CNewSpecialForm.h"
using namespace std;
//---------------------------------------------------------------------------
class TFrmShowInvoices : public NewSpecialForm
{
__published:	// IDE-managed Components
	TIBDataSet *DatasetInvoice;
	TImageList *ImageList1;
	TJvPanel *PanelTop;
	TJvPanel *PanelSearch;
	TJvExpressButton *btnAdd;
	TJvPanel *JvPanel1;
	TJvToolBar *JvToolBar1;
	TToolButton *ToolPrevious;
	TToolButton *ToolNext;
	TToolButton *ToolButton4;
	TToolButton *ToolAdd;
	TToolButton *ToolDelete;
	TToolButton *ToolButton8;
	TToolButton *ToolEdit;
	TToolButton *ToolAccept;
	TToolButton *ToolCancel;
	TToolButton *ToolButton2;
	TToolButton *ToolRefresh;
	TJvRollOut *RollDetail;
	TStatusBar *StatusBar1;
	TDataSource *DSInvoices;
	TIntegerField *DatasetInvoiceINVOICE_ID;
	TIBStringField *DatasetInvoiceINVCODE;
	TIntegerField *DatasetInvoiceCUST_ID;
	TIBStringField *DatasetInvoiceINVTYPE;
	TDateField *DatasetInvoiceINVDATE;
	TSmallintField *DatasetInvoicePRINTED;
	TDateField *DatasetInvoiceDELIVERYDATE;
	TIntegerField *DatasetInvoiceDISTRAIM_ID;
	TIntegerField *DatasetInvoiceDELMETHOD_ID;
	TIntegerField *DatasetInvoicePAYMETH_ID;
	TIBBCDField *DatasetInvoicePRICE;
	TIBBCDField *DatasetInvoicePRICEWVAT;
	TIntegerField *DatasetInvoiceCONV_INVOICE_ID;
	TIBStringField *DatasetInvoicePAYMETH_DESC;
	TJvPageControl *JvPageControl1;
	TTabSheet *TabSheet1;
	TJvDBLookupCombo *JvDBLookupCombo2;
	TLabel *Label5;
	TLabel *Label4;
	TLabel *Label2;
	TLabel *Label1;
	TLabel *Label3;
	TJvDBLookupCombo *lookupPayMeth;
	TLabel *Label10;
	TJvDBLookupCombo *lookupDelMethod;
	TLabel *Label9;
	TJvDBLookupCombo *lookupDistrAim;
	TLabel *Label8;
	TLabel *Label7;
	TLabel *Label6;
	TLabel *Label11;
	TJvDotNetDBMemo *JvDotNetDBMemo1;
	TTabSheet *TabSheet2;
	TDataSource *DSDistAim;
	TIBQuery *QryDistAim;
	TIntegerField *QryDistAimDISTAIM_ID;
	TIBStringField *QryDistAimDESCRIPTION;
	TIBQuery *QryDeliveryMethod;
	TIntegerField *QryDeliveryMethodMETHOD_ID;
	TIBStringField *QryDeliveryMethodDESCRIPTION;
	TDataSource *DSDeliveryMethod;
	TIBQuery *QryPaymentMeth;
	TIntegerField *QryPaymentMethMETHOD_ID;
	TIBStringField *QryPaymentMethDESCRIPTION;
	TIntegerField *QryPaymentMethDUE_DAYS;
	TDataSource *DSPaymentMeth;
	TIBQuery *QryInvTypes;
	TIBStringField *QryInvTypesINVTYPE_ID;
	TIBStringField *QryInvTypesNAME;
	TDataSource *DSInvTypes;
	TIBQuery *QrySelCustomer;
	TIBQuery *QrySelProducts;
	TIntegerField *QrySelProductsPRODUCT_ID;
	TIBStringField *QrySelProductsBARCODE;
	TIBStringField *QrySelProductsDESCRIPTION_SHORT;
	TIntegerField *QrySelProductsCAT_ID;
	TIntegerField *QrySelProductsVATCAT_ID;
	TIBBCDField *QrySelProductsBUY_PRICE;
	TIBBCDField *QrySelProductsSELL_PRICE;
	TIBBCDField *QrySelProductsPRICE_WVAT;
	TDateField *QrySelProductsDATE_INSERTED;
	TIntegerField *QrySelProductsRESERVE;
	TIntegerField *QrySelProductsRESERVE_SECURE;
	TMemoField *QrySelProductsDESCRIPTION;
	TIntegerField *QrySelProductsVAT_VALUE;
	TJvDotNetButton *JvDotNetButton1;
	TJvDotNetButton *JvDotNetButton2;
	TJvDotNetButton *JvDotNetButton3;
	TIBStringField *QryInvTypesFRM_FILENAME;
	TIBBCDField *DatasetInvoiceDISCOUNT;
	TTimeField *DatasetInvoiceINVTIME;
	TJvDotNetButton *JvDotNetButton4;
	TcxDBTextEdit *cxDBTextEdit1;
	TDataSource *DSSelCustomer;
	TcxDBTextEdit *cxDBTextEdit2;
	TcxDBTextEdit *cxDBTextEdit3;
	TcxDBTextEdit *cxDBTextEdit4;
	TcxDBTextEdit *cxDBTextEdit5;
	TIntegerField *QrySelCustomerCUST_ID;
	TIBStringField *QrySelCustomerAFM;
	TIBStringField *QrySelCustomerNAME;
	TIBStringField *QrySelCustomerADDRESS1;
	TIBStringField *QrySelCustomerADDRESS2;
	TIBStringField *QrySelCustomerCITY;
	TIBStringField *QrySelCustomerPOSTCODE;
	TIBStringField *QrySelCustomerPHONE1;
	TIBStringField *QrySelCustomerPHONE2;
	TIBStringField *QrySelCustomerFAX;
	TIBStringField *QrySelCustomerOCCUPATION;
	TIBStringField *QrySelCustomerTAXOFFICE;
	TWideMemoField *QrySelCustomerDETAILS;
	TIBBCDField *QrySelCustomerDISCOUNT;
	TIBStringField *QrySelCustomerEMAIL;
	TIntegerField *QrySelCustomerORDER;
	TIBStringField *QrySelCustomerCOUNTRY;
	TIBStringField *DatasetInvoiceADDRESS1;
	TIBStringField *DatasetInvoiceADDRESS2;
	TIBStringField *DatasetInvoiceCITY;
	TIBStringField *DatasetInvoicePOSTCODE;
	TIBStringField *DatasetInvoiceCOUNTRY;
	TIBStringField *DatasetInvoiceCUSTNAME;
	TIBStringField *DatasetInvoiceNOTES;
	TcxStyleRepository *StyleRepo;
	TcxStyle *StyleMain;
	TcxStyle *StyleEven;
	TcxStyle *StyleOdd;
	TcxStyle *StyleGroupBox;
	TJvDotNetButton *JvDotNetButton5;
	TcxDBDateEdit *cxDBDateEdit1;
	TcxDBDateEdit *cxDBDateEdit2;
	TTabSheet *TabSheet3;
	TcxGridDBTableView *ViewMyData;
	TcxGridLevel *GridMyDataLevel1;
	TcxGrid *GridMyData;
	TDataSource *DSMyData;
	TcxGridDBColumn *ID;
	TcxGridDBColumn *MARK;
	TcxGridDBColumn *DATE;
	TcxGridDBColumn *TIME;
	TJvDotNetButton *cmdSendMyData;
	TIBQuery *QryInvLines;
	TIntegerField *QryInvLinesINVLINE_ID;
	TIntegerField *QryInvLinesINVOICE_ID;
	TIntegerField *QryInvLinesPRODUCT_ID;
	TIBBCDField *QryInvLinesQTY;
	TIBBCDField *QryInvLinesPRICE_PER_ITEM;
	TIBBCDField *QryInvLinesDISCOUNT;
	TIBBCDField *QryInvLinesVATPERCENT;
	TIBBCDField *QryInvLinesPRICE;
	TIBBCDField *QryInvLinesPRICEWVAT;
	TIBStringField *QryInvLinesPRODUCT_DESCR;
	TIBStringField *QryInvLinesMETRIC_UNIT;
	TIBStringField *QryInvLinesNOTES;
	TIntegerField *DatasetInvoiceCODE;
	TWideMemoField *DatasetInvoiceNOTES_OLD;
	TIBStringField *QryInvTypesEAFDSS_SCRIPT;
	TIntegerField *QryInvTypesINVCOUNT;
	TSmallintField *QryInvTypesSHOW_ON_MENU;
	TIBStringField *QryInvTypesPRINTER_NAME;
	TSmallintField *QryInvTypesPRINTER_NO;
	TIntegerField *QryInvTypesDISTAIM_ID;
	TIntegerField *QryInvTypesDELIVERYMETHOD_ID;
	TIntegerField *QryInvTypesPAYMETH_ID;
	TIntegerField *QryInvTypesCUST_ID;
	TSmallintField *QryInvTypesCREDITINVOICE;
	TSmallintField *QryInvTypesRETURNINVOICE;
	TIBStringField *QryInvTypesMYDATA_TYPE;
	TIBStringField *QryInvTypesMYDATA_INCOME_CLASS;
	TIBStringField *QryInvTypesMYDATA_INCOME_CLASS_CATEGORY;
	TJvDotNetButton *cmdCancelInvoice;
	TIBDataSet *DatasetMyData;
	TIntegerField *DatasetMyDataID;
	TIBStringField *DatasetMyDataMARK;
	TIntegerField *DatasetMyDataINVOICE_ID;
	TIBStringField *DatasetMyDataRESPONSE;
	TDateField *DatasetMyDataDATE;
	TDateTimeField *DatasetMyDataTIME;
	TIBStringField *DatasetMyDataMYDATA_ACTION;
	TcxGridDBColumn *ViewMyDataMYDATA_ACTION;
	TJvPanel *PanelMain;
	TcxGrid *GridInvoices;
	TcxGridDBTableView *ViewInvoices;
	TcxGridDBColumn *ViewInvoicesINVOICE_ID;
	TcxGridDBColumn *ViewInvoicesINVCODE;
	TcxGridDBColumn *ViewInvoicesINVTYPE;
	TcxGridDBColumn *ViewInvoicesINVDATE;
	TcxGridDBColumn *ViewInvoicesCUSTNAME;
	TcxGridDBColumn *ViewInvoicesPAYMETH_DESC;
	TcxGridDBColumn *ViewInvoicesPRICE;
	TcxGridDBColumn *ViewInvoicesPRICEWVAT;
	TcxGridLevel *GridInvoicesLevel1;
	TSmallintField *DatasetInvoiceMYDATA_SENT;
	TIBStringField *DatasetInvoiceCOMPANY_NAME;
	TIBStringField *DatasetInvoiceVAT_NO;
	TIBStringField *DatasetInvoiceVIES_VAT;
	TIBStringField *DatasetInvoiceOCCUPATION;
	TIBStringField *DatasetInvoiceEMAIL_SENT;
	TIBBCDField *DatasetInvoiceWITHHOLD_AMOUNT;
	TSmallintField *DatasetInvoiceMAILED;
	TIBStringField *DatasetInvoiceMYDATA_STATE;
	TIBStringField *DatasetInvoiceMYDATA_MARK;
	TIBStringField *DatasetInvoiceMYDATA_URL;
	TJvDotNetButton *JvDotNetButton6;
	TJvDotNetButton *cmdSendMyData2;
	void __fastcall DatasetInvoiceAfterScroll(TDataSet *DataSet);
	void __fastcall FormCloseQuery(TObject *Sender, bool &CanClose);
	void __fastcall ToolEditClick(TObject *Sender);
	void __fastcall ToolDeleteClick(TObject *Sender);
	void __fastcall JvDotNetButton1Click(TObject *Sender);
	void __fastcall FormShow(TObject *Sender);
	void __fastcall JvDotNetButton4Click(TObject *Sender);
	void __fastcall JvDotNetButton5Click(TObject *Sender);
	void __fastcall DatasetInvoiceAfterEdit(TDataSet *DataSet);
	void __fastcall ToolAcceptClick(TObject *Sender);
	void __fastcall DatasetInvoiceAfterPost(TDataSet *DataSet);
	void __fastcall ToolCancelClick(TObject *Sender);
	void __fastcall DatasetInvoiceAfterCancel(TDataSet *DataSet);
	void __fastcall cmdSendMyDataClick(TObject *Sender);
	void __fastcall cmdCancelInvoiceClick(TObject *Sender);
	void __fastcall DatasetMyDataAfterOpen(TDataSet *DataSet);
	void __fastcall DatasetMyDataAfterScroll(TDataSet *DataSet);


private:	// User declarations
	vector<EditBox *>  editBoxes;
	vector<EditBox *> garbage;
	TStringList *defaultSQL;
	void __fastcall btnMinusClick(TObject *Sender);
	void __fastcall editSearchChange(TObject *Sender);
	void setCustomerId(int _id);
	void setProductId(int _prId);


public:		// User declarations
	__fastcall TFrmShowInvoices(TComponent* Owner);
	void setInvoiceId(AnsiString _invCode);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmShowInvoices *FrmShowInvoices;
//---------------------------------------------------------------------------
#endif
